<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Quiz report: Paper Entry — multi-grader comparison workflow.
 *
 * @package     quiz_paperentry
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once(__DIR__ . '/classes/import_form.php');

use quiz_paperentry\export;
use quiz_paperentry\import;
use quiz_paperentry\settings_manager;
use quiz_paperentry\comparison;
use mod_quiz\local\reports\report_base;

/**
 * Main controller class for the Paper Entry quiz report.
 *
 * Handles capability checks, HTTP action dispatch (export, settings, grader
 * management, final import, override import), and delegates rendering to the
 * private render_* helpers below.  Two role-specific views are served:
 *  - Manager view  (quiz/paperentry:manage): full settings + grader management
 *                   + submissions comparison + direct override import.
 *  - Grader view   (assigned via settings_manager): export download + CSV
 *                   submission upload + read-only comparison panel.
 */
class quiz_paperentry_report extends report_base {

    /**
     * Entry point called by the quiz report framework.
     *
     * Dispatches GET/POST actions, then renders the appropriate role view.
     * Returns false only when Moodle itself should halt further processing;
     * in practice this method always returns true.
     *
     * @param \stdClass $quiz    Quiz record from {quiz}.
     * @param \stdClass $cm      Course-module record.
     * @param \stdClass $course  Course record.
     * @return bool True on success.
     */
    public function display($quiz, $cm, $course): bool {
        global $OUTPUT, $PAGE, $CFG, $USER;

        $context = context_module::instance($cm->id);
        require_capability('quiz/paperentry:view', $context);

        $ismanager = has_capability('quiz/paperentry:manage', $context);
        // Grader access is controlled by the quiz/paperentry:submit capability, which
        // the manager grants per-user via the Graders panel (assign_capability).
        $isgrader  = !$ismanager && has_capability('quiz/paperentry:submit', $context);

        $action          = optional_param('action', '', PARAM_ALPHAEXT);
        $pageurl         = new \moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'paperentry']);
        $overrideresults = null;

        // Export action — streams CSV and exits.
        if ($action === 'export') {
            require_sesskey();
            // Block export if any question still has shuffle enabled.
            if (empty(export::get_shuffled_questions($quiz))) {
                export::send_csv($quiz, $cm, $course); // Exits.
            }
            // Otherwise fall through to page render which will show the warning.
        }

        // Handle simple POST actions before outputting the page header.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            confirm_sesskey();

            if ($action === 'savesettings' && $ismanager) {
                $fields = optional_param_array('extrafields', [], PARAM_NOTAGS);
                $available = export::get_available_profile_fields();
                $fields = array_values(array_filter((array)$fields, fn($f) => array_key_exists($f, $available)));
                settings_manager::save_extra_fields($quiz->id, $fields);
                $remappings = self::read_remapping_post();
                settings_manager::save_remappings($quiz->id, $remappings);
                redirect($pageurl, get_string('settings_saved', 'quiz_paperentry'),
                    null, \core\output\notification::NOTIFY_SUCCESS);
            }

            if ($action === 'addgrader' && $ismanager) {
                $userid = required_param('userid', PARAM_INT);
                settings_manager::add_grader($quiz->id, $userid, $context);
                redirect($pageurl, get_string('grader_added', 'quiz_paperentry'), null, \core\output\notification::NOTIFY_SUCCESS);
            }

            if ($action === 'removegrader' && $ismanager) {
                $userid = required_param('userid', PARAM_INT);
                settings_manager::remove_grader($quiz->id, $userid, $context);
                redirect($pageurl, get_string('grader_removed', 'quiz_paperentry'),
                    null, \core\output\notification::NOTIFY_SUCCESS);
            }

            if ($action === 'editanswer' && $ismanager) {
                $eauserid = required_param('ea_userid', PARAM_INT);
                $easlot = required_param('ea_slot', PARAM_INT);
                $eaindex = required_param('ea_index', PARAM_INT);
                $importer = new import($quiz, $cm, $course);
                try {
                    $importer->override_answer($eauserid, $easlot, $eaindex);
                    $msg = get_string('editanswer_success', 'quiz_paperentry');
                    redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
                } catch (\Throwable $e) {
                    // Fall through to page render with error shown as a notification.
                    \core\notification::error($e->getMessage());
                }
            }

            if ($action === 'finalimport' && $ismanager) {
                $submissions = settings_manager::get_all_submissions($quiz->id);
                if (!empty($submissions)) {
                    $first       = reset($submissions);
                    $firstfile   = settings_manager::get_submission_file((int)$first->userid, $context);
                    $tempdir  = make_temp_directory('quiz_paperentry');
                    $tempfile = $tempdir . '/finalimport_' . $quiz->id . '.csv';
                    if ($firstfile) {
                        $firstfile->copy_content_to($tempfile);
                    }
                    $importer = new import($quiz, $cm, $course);
                    $importer->set_remapping(self::read_remapping_post());
                    $importer->process($tempfile);
                    unlink($tempfile);
                    if ($importer->imported > 0 && empty($importer->errors) && empty($importer->warnings)) {
                        $msg = get_string('finalimport_done', 'quiz_paperentry', $importer->imported);
                        redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
                    }
                    // Fall through to page render so errors/warnings are visible.
                    $overrideresults = new \stdClass();
                    $overrideresults->errors   = $importer->errors;
                    $overrideresults->warnings = $importer->warnings;
                    $overrideresults->imported = $importer->imported;
                    $overrideresults->skipped  = $importer->skipped;
                }
            }
        }

        // Grader submit form.
        $graderformurl = new \moodle_url('/mod/quiz/report.php',
            ['id' => $cm->id, 'mode' => 'paperentry', 'action' => 'gradersubmit']);
        $graderform = new \quiz_paperentry\import_form($graderformurl);

        // Override import form.
        $overrideformurl = new \moodle_url('/mod/quiz/report.php',
            ['id' => $cm->id, 'mode' => 'paperentry', 'action' => 'override']);
        $overrideform = $ismanager ? new \quiz_paperentry\import_form($overrideformurl) : null;

        // Handle grader submit action.
        if ($action === 'gradersubmit' && ($isgrader || $ismanager)) {
            if ($graderform->is_submitted() && $graderform->is_validated()) {
                $data        = $graderform->get_data();
                $draftitemid = $data->csvfile ?? 0;
                $usercontext = \context_user::instance($USER->id);
                $fs          = get_file_storage();
                $files       = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid,
                    'filename', false);
                if (!empty($files)) {
                    $file    = reset($files);
                    $csvdata = $file->get_content();
                    settings_manager::save_submission($quiz->id, $USER->id, $csvdata, $context);
                }
                redirect($pageurl, get_string('gradersubmit_success', 'quiz_paperentry'), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }
        }

        // Handle override import action.
        if ($action === 'override' && $ismanager && $overrideform !== null) {
            if ($overrideform->is_submitted() && $overrideform->is_validated()) {
                $data        = $overrideform->get_data();
                $draftitemid = $data->csvfile ?? 0;
                $usercontext = \context_user::instance($USER->id);
                $fs          = get_file_storage();
                $files       = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid,
                    'filename', false);
                if (!empty($files)) {
                    $uploadedfile = reset($files);
                    $tempdir  = make_temp_directory('quiz_paperentry');
                    $tempfile = $tempdir . '/' . clean_filename($uploadedfile->get_filename());
                    $uploadedfile->copy_content_to($tempfile);
                    $importer = new import($quiz, $cm, $course);
                    $importer->process($tempfile);
                    unlink($tempfile);
                    $overrideresults = new \stdClass();
                    $overrideresults->errors   = $importer->errors;
                    $overrideresults->warnings = $importer->warnings;
                    $overrideresults->imported = $importer->imported;
                    $overrideresults->skipped  = $importer->skipped;
                }
            }
        }

        // Page header and tabs.
        $this->print_header_and_tabs($cm, $course, $quiz, 'paperentry');
        echo $OUTPUT->heading(format_string($quiz->name) . ': ' .
            get_string('paperentry', 'quiz_paperentry'));

        $questions = export::get_questions($quiz);
        $students  = export::get_enrolled_students($cm, $course);

        if (empty($questions)) {
            echo $OUTPUT->notification(get_string('noquestions', 'quiz_paperentry'), 'warning');
            return true;
        }
        if (empty($students)) {
            echo $OUTPUT->notification(get_string('nostudents', 'quiz_paperentry'), 'warning');
            return true;
        }

        // Shuffle check — shown to all roles before the export sections.
        $shuffled = export::get_shuffled_questions($quiz);

        // Render role-specific view.
        if ($ismanager) {
            $this->render_manager_view(
                $quiz, $cm, $course, $questions, $students, $shuffled,
                $graderform, $overrideform, $overrideresults, $pageurl
            );
        } else if ($isgrader) {
            $this->render_grader_view($quiz, $cm, $course, $questions, $shuffled, $graderform, $pageurl);
        } else {
            echo $OUTPUT->notification(get_string('noaccess', 'quiz_paperentry'), 'warning');
        }

        return true;
    }


    /**
     * Render the complete manager view (settings, graders, export, submissions, override).
     *
     * @param \stdClass                         $quiz            Quiz record.
     * @param \stdClass                         $cm              Course-module record.
     * @param \stdClass                         $course          Course record.
     * @param \stdClass[]                       $questions       Supported questions for this quiz.
     * @param \stdClass[]                       $students        Enrolled students keyed by userid.
     * @param \stdClass[]                       $shuffled        Questions with shuffle answers enabled.
     * @param \quiz_paperentry\import_form      $graderform      Grader CSV upload form.
     * @param \quiz_paperentry\import_form|null $overrideform    Override import form (manager only).
     * @param \stdClass|null                    $overrideresults Results of a just-processed override import.
     * @param \moodle_url                       $pageurl         Base URL for this report page.
     * @return void
     */
    private function render_manager_view(
        \stdClass $quiz, $cm, \stdClass $course,
        array $questions,
        array $students,
        array $shuffled,
        \quiz_paperentry\import_form $graderform,
        ?\quiz_paperentry\import_form $overrideform,
        ?\stdClass $overrideresults,
        \moodle_url $pageurl
    ): void {
        $this->render_settings_section($quiz, $cm, $course, $pageurl);
        $this->render_graders_section($quiz, $cm, $course, $pageurl);
        $this->render_shuffle_warning($shuffled, $quiz, $cm);
        $this->render_manager_export_section($quiz, $cm, $course, $pageurl, $shuffled);
        $this->render_submissions_section($quiz, $questions, $pageurl);
        $this->render_question_reference($questions, 'paperentry-qref-manager', true,
            settings_manager::get_remappings($quiz->id));
        $this->render_override_section($quiz, $overrideform, $overrideresults);
        $this->render_edit_answer_section($quiz, $cm, $questions, $students);
    }


    /**
     * Render the Export Settings card with the profile-field tag picker.
     *
     * @param \stdClass   $quiz    Quiz record.
     * @param \stdClass   $cm      Course-module record.
     * @param \stdClass   $course  Course record.
     * @param \moodle_url $pageurl Base report URL used to build the form action.
     * @return void
     */
    private function render_settings_section(\stdClass $quiz, $cm, \stdClass $course, \moodle_url $pageurl): void {
        global $PAGE;

        $savedfields     = settings_manager::get_extra_fields($quiz->id);
        $savedremappings = settings_manager::get_remappings($quiz->id);
        $availablefields = export::get_available_profile_fields();

        // Build dropdown options — skip already-saved ones.
        $optionshtml = \html_writer::tag('option',
            get_string('exportfields_placeholder', 'quiz_paperentry'), ['value' => '']);
        foreach ($availablefields as $key => $label) {
            if (!in_array($key, $savedfields, true)) {
                $optionshtml .= \html_writer::tag('option', s($label), ['value' => s($key)]);
            }
        }

        $formaction = new \moodle_url('/mod/quiz/report.php',
            ['id' => $cm->id, 'mode' => 'paperentry', 'action' => 'savesettings']);

        echo \html_writer::start_div('card mb-4');
        echo \html_writer::start_div('card-header bg-info text-white');
        echo \html_writer::tag('h5', get_string('settings_section', 'quiz_paperentry'), ['class' => 'mb-0']);
        echo \html_writer::end_div();
        echo \html_writer::start_div('card-body');
        echo \html_writer::tag('p', get_string('settings_desc', 'quiz_paperentry'));

        echo \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $formaction->out(false),
            'id'     => 'paperentry-settings-form',
        ]);
        echo \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        echo \html_writer::start_div('form-group mb-3');
        echo \html_writer::tag('label',
            get_string('exportfields_label', 'quiz_paperentry'),
            ['for' => 'settings-field-picker', 'class' => 'font-weight-bold']);
        echo \html_writer::tag('select', $optionshtml, [
            'id'    => 'settings-field-picker',
            'class' => 'form-control mt-1',
            'style' => 'max-width:320px',
        ]);
        // Tag area.
        echo \html_writer::tag('div', '', [
            'id'    => 'settings-field-tags',
            'class' => 'mt-2 d-flex flex-wrap',
            'style' => 'gap:6px',
        ]);
        // Hidden inputs container (pre-populated by JS from saved fields).
        echo \html_writer::tag('div', '', ['id' => 'settings-hidden-inputs']);
        echo \html_writer::end_div();

        // Default value substitutions widget.
        echo \html_writer::start_div('form-group mt-4 mb-3');
        echo \html_writer::tag('label',
            get_string('remap_default_label', 'quiz_paperentry'),
            ['class' => 'font-weight-bold d-block mb-1']);
        echo \html_writer::tag('small',
            get_string('remap_default_desc', 'quiz_paperentry'),
            ['class' => 'text-muted d-block mb-2']);
        $this->render_mapping_widget('pe-settings-map', false);
        echo \html_writer::end_div();

        echo \html_writer::empty_tag('input', [
            'type'  => 'submit',
            'class' => 'btn btn-info',
            'value' => get_string('savesettings', 'quiz_paperentry'),
        ]);

        echo \html_writer::end_tag('form');
        echo \html_writer::end_div();
        echo \html_writer::end_div();

        // Initialise the AMD tag-picker module and pre-populate saved remappings.
        $PAGE->requires->js_call_amd('quiz_paperentry/settings_picker', 'init',
            [$savedfields, array_map('s', $availablefields)]);
        $PAGE->requires->js_call_amd('quiz_paperentry/mapping_widget', 'initWithSaved',
            ['pe-settings-map',
             get_string('remap_from', 'quiz_paperentry'),
             get_string('remap_to', 'quiz_paperentry'),
             false,
             $savedremappings]);
    }


    /**
     * Render the Graders card showing assigned graders, submission status, and the add-grader form.
     *
     * @param \stdClass   $quiz    Quiz record.
     * @param \stdClass   $cm      Course-module record.
     * @param \stdClass   $course  Course record.
     * @param \moodle_url $pageurl Base report URL used to build add/remove form actions.
     * @return void
     */
    private function render_graders_section(\stdClass $quiz, $cm, \stdClass $course, \moodle_url $pageurl): void {
        $graders    = settings_manager::get_graders($quiz->id);
        $submissions = settings_manager::get_all_submissions($quiz->id);

        $potential  = settings_manager::get_potential_graders($cm, $course);
        $available  = array_diff_key($potential, $graders);

        $addurl    = new \moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'paperentry', 'action' => 'addgrader']);
        $removeurl = new \moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'paperentry', 'action' => 'removegrader']);

        echo \html_writer::start_div('card mb-4');
        echo \html_writer::start_div('card-header bg-secondary text-white');
        echo \html_writer::tag('h5', get_string('graders_section', 'quiz_paperentry'), ['class' => 'mb-0']);
        echo \html_writer::end_div();
        echo \html_writer::start_div('card-body');
        echo \html_writer::tag('p', get_string('graders_desc', 'quiz_paperentry'));

        if (empty($graders)) {
            echo \html_writer::tag('p',
                \html_writer::tag('em', get_string('graders_none', 'quiz_paperentry')),
                ['class' => 'text-muted']);
        } else {
            $table = new \html_table();
            $table->head = ['Name', 'Email', 'Status', 'Action'];
            $table->attributes['class'] = 'table table-sm table-bordered';
            foreach ($graders as $grader) {
                $submitted = isset($submissions[(int)$grader->id]);
                $status = $submitted
                    ? \html_writer::tag('span',
                        get_string('grader_submitted', 'quiz_paperentry'), ['class' => 'badge badge-success'])
                    : \html_writer::tag('span',
                        get_string('grader_pending', 'quiz_paperentry'), ['class' => 'badge badge-warning']);
                $removebtn = \html_writer::start_tag('form',
                        ['method' => 'post', 'action' => $removeurl->out(false), 'class' => 'd-inline'])
                    . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                    . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $grader->id])
                    . \html_writer::empty_tag('input',
                        ['type' => 'submit', 'class' => 'btn btn-sm btn-danger', 'value' => 'Remove'])
                    . \html_writer::end_tag('form');
                $row = new \html_table_row([
                    fullname($grader),
                    s($grader->email),
                    $status,
                    $removebtn,
                ]);
                $table->data[] = $row;
            }
            echo \html_writer::table($table);
        }

        // Add grader form.
        echo \html_writer::start_tag('form', ['method' => 'post', 'action' => $addurl->out(false), 'class' => 'form-inline mt-2']);
        echo \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        if (empty($available)) {
            echo \html_writer::tag('span', get_string('noavailablegraders', 'quiz_paperentry'), ['class' => 'text-muted']);
        } else {
            $opts = \html_writer::tag('option', get_string('addgrader_pick', 'quiz_paperentry'), ['value' => '']);
            foreach ($available as $u) {
                $opts .= \html_writer::tag('option', s(fullname($u)) . ' (' . s($u->email) . ')', ['value' => $u->id]);
            }
            echo \html_writer::tag('select', $opts,
                ['name' => 'userid', 'class' => 'form-control mr-2', 'style' => 'max-width:300px']);
            echo \html_writer::empty_tag('input', [
                'type' => 'submit', 'class' => 'btn btn-secondary',
                'value' => get_string('addgrader', 'quiz_paperentry'),
            ]);
        }
        echo \html_writer::end_tag('form');

        echo \html_writer::end_div();
        echo \html_writer::end_div();
    }


    /**
     * Render the Export CSV card for the manager view.
     *
     * Shows a disabled button when shuffle-answer questions are detected.
     *
     * @param \stdClass   $quiz     Quiz record.
     * @param \stdClass   $cm       Course-module record.
     * @param \stdClass   $course   Course record.
     * @param \moodle_url $pageurl  Base report URL (unused here; kept for signature consistency).
     * @param \stdClass[] $shuffled Questions that still have shuffle answers enabled.
     * @return void
     */
    private function render_manager_export_section(
        \stdClass $quiz, $cm, \stdClass $course, \moodle_url $pageurl, array $shuffled = []
    ): void {
        $exporturl = new \moodle_url('/mod/quiz/report.php', [
            'id'      => $cm->id,
            'mode'    => 'paperentry',
            'action'  => 'export',
            'sesskey' => sesskey(),
        ]);

        echo \html_writer::start_div('card mb-4');
        echo \html_writer::start_div('card-header bg-primary text-white');
        echo \html_writer::tag('h5', get_string('exportcsv', 'quiz_paperentry'), ['class' => 'mb-0']);
        echo \html_writer::end_div();
        echo \html_writer::start_div('card-body');
        echo \html_writer::tag('p', get_string('exportdesc', 'quiz_paperentry'));
        echo \html_writer::tag('p', 'Profile fields are configured in Export Settings above.');
        if (!empty($shuffled)) {
            echo \html_writer::empty_tag('input', [
                'type' => 'button', 'class' => 'btn btn-primary disabled',
                'value' => get_string('exportcsv', 'quiz_paperentry'),
                'disabled' => 'disabled',
                'title' => get_string('shufflewarning_title', 'quiz_paperentry'),
            ]);
        } else {
            echo \html_writer::link($exporturl,
                \html_writer::tag('i', '', ['class' => 'fa fa-download mr-1']) .
                get_string('exportcsv', 'quiz_paperentry'),
                ['class' => 'btn btn-primary']);
        }
        echo \html_writer::end_div();
        echo \html_writer::end_div();
    }


    /**
     * Render the Grader Submissions & Comparison card.
     *
     * Runs a cross-grader comparison when two or more submissions exist and
     * exposes the "Submit to Gradebook" button only when all graders agree.
     *
     * @param \stdClass   $quiz      Quiz record.
     * @param \stdClass[] $questions Supported quiz questions (used for question names).
     * @param \moodle_url $pageurl   Base report URL used to build the final-import form action.
     * @return void
     */
    private function render_submissions_section(\stdClass $quiz, array $questions, \moodle_url $pageurl): void {
        global $DB;

        $context     = \context_module::instance($quiz->coursemodule ?? get_coursemodule_from_instance('quiz', $quiz->id)->id);
        $graders     = settings_manager::get_graders($quiz->id);
        $submissions = settings_manager::get_all_submissions($quiz->id);
        $allsubmitted = settings_manager::has_all_graders_submitted($quiz->id);

        $submittedcount = count($submissions);
        $totalcount     = count($graders);

        // Determine header color.
        if (empty($graders)) {
            $headercls = 'bg-secondary text-white';
        } else if ($allsubmitted) {
            $headercls = 'bg-success text-white';
        } else {
            $headercls = 'bg-warning text-dark';
        }

        echo \html_writer::start_div('card mb-4');
        echo \html_writer::start_div('card-header ' . $headercls);
        echo \html_writer::tag('h5', get_string('submissions_section', 'quiz_paperentry'), ['class' => 'mb-0']);
        echo \html_writer::end_div();
        echo \html_writer::start_div('card-body');

        if (empty($graders)) {
            echo \html_writer::tag('p', get_string('submissions_none_graders', 'quiz_paperentry'), ['class' => 'text-muted']);
        } else {
            $waitingstr = get_string('submissions_waiting', 'quiz_paperentry',
                (object)['submitted' => $submittedcount, 'total' => $totalcount]);
            echo \html_writer::tag('p', $waitingstr);

            // Run comparison if ≥2 submitted.
            $compresult = null;
            if ($submittedcount >= 2) {
                $csvmap = settings_manager::get_all_submissions_csv($quiz->id, $context);
                $qnames = array_map(fn($q) => export::csv_safe($q->name), $questions);
                $compresult = comparison::compare($csvmap, $qnames, $graders);

                if ($compresult['all_match']) {
                    echo \html_writer::tag('p',
                        get_string('comparison_all_match', 'quiz_paperentry'),
                        ['class' => 'text-success font-weight-bold']);
                } else {
                    $cnt = count($compresult['mismatches']);
                    echo \html_writer::tag('p',
                        get_string('comparison_mismatches', 'quiz_paperentry', $cnt),
                        ['class' => 'text-danger font-weight-bold']);
                    echo $this->render_mismatch_table($compresult);
                }
            }

            // Submit to gradebook button.
            $cansubmit = $allsubmitted && ($compresult === null || $compresult['all_match']);
            $finalurl  = new \moodle_url('/mod/quiz/report.php',
                ['id' => $quiz->cmid ?? optional_param('id', 0, PARAM_INT), 'mode' => 'paperentry', 'action' => 'finalimport']);

            echo \html_writer::start_div('mt-3');
            // Mapping widget always visible — inputs injected into the form on submit.
            $this->render_mapping_widget('pe-finalimport-map', true);

            if ($cansubmit) {
                echo \html_writer::tag('p', get_string('submit_final_desc', 'quiz_paperentry'));
                echo \html_writer::start_tag('form', [
                    'method' => 'post',
                    'action' => $finalurl->out(false),
                    'id'     => 'pe-finalimport-form',
                ]);
                echo \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo \html_writer::empty_tag('input', [
                    'type'  => 'submit',
                    'class' => 'btn btn-success mt-2',
                    'value' => get_string('submit_final', 'quiz_paperentry'),
                ]);
                echo \html_writer::end_tag('form');
            } else {
                if (!$allsubmitted) {
                    $reason = get_string('submit_disabled_pending', 'quiz_paperentry');
                } else {
                    $reason = get_string('submit_disabled_mismatches', 'quiz_paperentry');
                }
                echo \html_writer::tag('p', $reason, ['class' => 'text-muted font-italic']);
                echo \html_writer::empty_tag('input', [
                    'type'     => 'button',
                    'class'    => 'btn btn-success',
                    'value'    => get_string('submit_final', 'quiz_paperentry'),
                    'disabled' => 'disabled',
                ]);
            }
            echo \html_writer::end_div();
        }

        echo \html_writer::end_div();
        echo \html_writer::end_div();
    }

    /**
     * Build and return an HTML table showing per-student, per-question answer mismatches.
     *
     * Cells in the majority answer are highlighted green; outliers are red.
     *
     * @param array $compresult      Return value of comparison::compare().
     * @param bool  $showgradernames True to show real grader names (manager); false for anonymous labels (grader).
     * @return string HTML string of the rendered table.
     */
    private function render_mismatch_table(array $compresult, bool $showgradernames = true): string {
        $graderids   = $compresult['grader_ids'];
        $gradernames = $compresult['gradernames'];
        $mismatches  = $compresult['mismatches'];

        $table = new \html_table();
        $table->attributes['class'] = 'table table-sm table-bordered';
        $headers = [
            get_string('mismatch_col_student', 'quiz_paperentry'),
            get_string('mismatch_col_question', 'quiz_paperentry'),
        ];
        $graderlabel = 1;
        foreach ($graderids as $gid) {
            if ($showgradernames && isset($gradernames[$gid])) {
                $headers[] = fullname($gradernames[$gid]);
            } else {
                $headers[] = get_string('mismatch_col_grader', 'quiz_paperentry', $graderlabel);
            }
            $graderlabel++;
        }
        $table->head = $headers;

        foreach ($mismatches as $m) {
            $answers = $m['answers'];
            // Find majority value.
            $counts = array_count_values($answers);
            arsort($counts);
            $maxcount = reset($counts);
            $majority = ($maxcount > 1) ? key($counts) : null;

            $cells = [];
            $cells[] = new \html_table_cell(s($m['student']) . ' (' . $m['userid'] . ')');
            $cells[] = new \html_table_cell(s($m['question']));
            foreach ($graderids as $gid) {
                $val  = $answers[$gid] ?? '';
                $cell = new \html_table_cell(s($val));
                if ($majority !== null && $val === $majority) {
                    $cell->attributes['class'] = 'table-success';
                } else {
                    $cell->attributes['class'] = 'table-danger';
                }
                $cells[] = $cell;
            }
            $table->data[] = new \html_table_row($cells);
        }

        return \html_writer::table($table);
    }


    /**
     * Render the Direct Import / Admin Override card.
     *
     * @param \stdClass                         $quiz            Quiz record.
     * @param \quiz_paperentry\import_form|null $overrideform    Override upload form (null if user lacks manage cap).
     * @param \stdClass|null                    $overrideresults Results of a just-processed override import, or null.
     * @return void
     */
    private function render_override_section(
        \stdClass $quiz,
        ?\quiz_paperentry\import_form $overrideform,
        ?\stdClass $overrideresults
    ): void {
        global $OUTPUT;

        $collapseid = 'paperentry-override-collapse';

        // Import results are shown ABOVE the card (outside the collapse) so the
        // section never auto-opens — it always starts collapsed.
        if ($overrideresults !== null) {
            $this->render_import_results($overrideresults);
        }

        echo \html_writer::start_div('card mb-4 border-warning');
        echo \html_writer::start_div('card-header bg-warning text-dark');
        echo \html_writer::tag('h5',
            \html_writer::tag('button', get_string('override_section', 'quiz_paperentry'), [
                'class'          => 'btn btn-link collapsed text-dark text-left w-100 p-0',
                'type'           => 'button',
                'data-bs-toggle' => 'collapse',
                'data-bs-target' => '#' . $collapseid,
                'aria-expanded'  => 'false',
                'aria-controls'  => $collapseid,
            ]),
            ['class' => 'mb-0']
        );
        echo \html_writer::end_div();
        echo \html_writer::start_div('collapse', ['id' => $collapseid]);
        echo \html_writer::start_div('card-body');
        echo \html_writer::tag('p', get_string('override_desc', 'quiz_paperentry'));
        echo \html_writer::tag('p',
            \html_writer::tag('strong',
                \html_writer::tag('i', '', ['class' => 'fa fa-exclamation-triangle mr-1']) .
                get_string('override_warning', 'quiz_paperentry')),
            ['class' => 'text-danger']);
        if ($overrideform !== null) {
            $overrideform->display();
        }
        echo \html_writer::end_div(); // Card body.
        echo \html_writer::end_div(); // Collapse wrapper.
        echo \html_writer::end_div(); // Card.
    }


    /**
     * Render the complete grader view (question reference, export, submit, comparison).
     *
     * @param \stdClass                    $quiz       Quiz record.
     * @param \stdClass                    $cm         Course-module record.
     * @param \stdClass                    $course     Course record.
     * @param \stdClass[]                  $questions  Supported quiz questions.
     * @param \stdClass[]                  $shuffled   Questions with shuffle answers enabled.
     * @param \quiz_paperentry\import_form $graderform Grader CSV upload form.
     * @param \moodle_url                  $pageurl    Base report URL.
     * @return void
     */
    private function render_grader_view(
        \stdClass $quiz, $cm, \stdClass $course,
        array $questions,
        array $shuffled,
        \quiz_paperentry\import_form $graderform,
        \moodle_url $pageurl
    ): void {
        $remappings = settings_manager::get_remappings($quiz->id);
        $this->render_shuffle_warning($shuffled, $quiz, $cm);
        $this->render_grader_export_section($quiz, $cm, $course, $shuffled);
        $this->render_grader_submit_section($quiz, $graderform);
        $this->render_grader_comparison_section($quiz, $questions);
        $this->render_question_reference($questions, 'paperentry-qref-grader', true, $remappings);
    }

    /**
     * Render the Download Answer Sheet card for the grader view.
     *
     * @param \stdClass   $quiz     Quiz record.
     * @param \stdClass   $cm       Course-module record.
     * @param \stdClass   $course   Course record.
     * @param \stdClass[] $shuffled Questions with shuffle answers enabled (disables the export button).
     * @return void
     */
    private function render_grader_export_section(\stdClass $quiz, $cm, \stdClass $course, array $shuffled = []): void {
        $exporturl = new \moodle_url('/mod/quiz/report.php', [
            'id'      => $cm->id,
            'mode'    => 'paperentry',
            'action'  => 'export',
            'sesskey' => sesskey(),
        ]);
        $savedfields = settings_manager::get_extra_fields($quiz->id);

        echo \html_writer::start_div('card mb-4');
        echo \html_writer::start_div('card-header bg-primary text-white');
        echo \html_writer::tag('h5', get_string('grader_export_section', 'quiz_paperentry'), ['class' => 'mb-0']);
        echo \html_writer::end_div();
        echo \html_writer::start_div('card-body');
        echo \html_writer::tag('p', get_string('grader_export_desc', 'quiz_paperentry'));
        if (!empty($savedfields)) {
            $available = export::get_available_profile_fields();
            $labels = array_map(fn($k) => $available[$k] ?? $k, $savedfields);
            echo \html_writer::tag('p', 'Extra columns included: ' . implode(', ', array_map('s', $labels)));
        }
        if (!empty($shuffled)) {
            echo \html_writer::empty_tag('input', [
                'type' => 'button', 'class' => 'btn btn-primary disabled',
                'value' => get_string('exportcsv', 'quiz_paperentry'),
                'disabled' => 'disabled',
                'title' => get_string('shufflewarning_title', 'quiz_paperentry'),
            ]);
        } else {
            echo \html_writer::link($exporturl,
                \html_writer::tag('i', '', ['class' => 'fa fa-download mr-1']) .
                get_string('exportcsv', 'quiz_paperentry'),
                ['class' => 'btn btn-primary']);
        }
        echo \html_writer::end_div();
        echo \html_writer::end_div();
    }

    /**
     * Render the Submit Your Answers card for the grader view.
     *
     * Shows the last submission timestamp if the current grader has already uploaded.
     *
     * @param \stdClass                    $quiz       Quiz record.
     * @param \quiz_paperentry\import_form $graderform Grader CSV upload form.
     * @return void
     */
    private function render_grader_submit_section(\stdClass $quiz, \quiz_paperentry\import_form $graderform): void {
        global $USER;

        $existing = settings_manager::get_submission($quiz->id, $USER->id);

        echo \html_writer::start_div('card mb-4');
        echo \html_writer::start_div('card-header bg-success text-white');
        echo \html_writer::tag('h5', get_string('grader_submit_section', 'quiz_paperentry'), ['class' => 'mb-0']);
        echo \html_writer::end_div();
        echo \html_writer::start_div('card-body');
        echo \html_writer::tag('p', get_string('grader_submit_desc', 'quiz_paperentry'));
        if ($existing) {
            $datestr = userdate($existing->timemodified);
            echo \html_writer::tag('p',
                \html_writer::tag('span',
                    get_string('grader_last_submitted', 'quiz_paperentry', $datestr),
                    ['class' => 'badge badge-success mr-2']));
        }
        $graderform->display();
        echo \html_writer::end_div();
        echo \html_writer::end_div();
    }

    /**
     * Render the Comparison with Other Graders card for the grader view.
     *
     * Shows a waiting notice when fewer than two submissions exist; otherwise
     * runs a full comparison and displays match/mismatch results.
     *
     * @param \stdClass   $quiz      Quiz record.
     * @param \stdClass[] $questions Supported quiz questions (used for question names).
     * @return void
     */
    private function render_grader_comparison_section(\stdClass $quiz, array $questions): void {
        $context     = \context_module::instance(get_coursemodule_from_instance('quiz', $quiz->id)->id);
        $submissions = settings_manager::get_all_submissions($quiz->id);
        if (count($submissions) < 2) {
            echo \html_writer::start_div('card mb-4');
            echo \html_writer::start_div('card-header');
            echo \html_writer::tag('h5', get_string('grader_comparison_section', 'quiz_paperentry'), ['class' => 'mb-0']);
            echo \html_writer::end_div();
            echo \html_writer::start_div('card-body');
            echo \html_writer::tag('p',
                get_string('grader_comparison_waiting', 'quiz_paperentry'),
                ['class' => 'text-muted']);
            echo \html_writer::end_div();
            echo \html_writer::end_div();
            return;
        }

        $graders = settings_manager::get_graders($quiz->id);
        $csvmap  = settings_manager::get_all_submissions_csv($quiz->id, $context);
        $qnames  = array_map(fn($q) => export::csv_safe($q->name), $questions);
        $result  = comparison::compare($csvmap, $qnames, $graders);

        $headercls = $result['all_match'] ? 'bg-success text-white' : 'bg-warning text-dark';

        echo \html_writer::start_div('card mb-4');
        echo \html_writer::start_div('card-header ' . $headercls);
        echo \html_writer::tag('h5', get_string('grader_comparison_section', 'quiz_paperentry'), ['class' => 'mb-0']);
        echo \html_writer::end_div();
        echo \html_writer::start_div('card-body');

        if ($result['all_match']) {
            echo \html_writer::tag('p',
                get_string('comparison_all_match', 'quiz_paperentry'),
                ['class' => 'text-success font-weight-bold']);
        } else {
            $cnt = count($result['mismatches']);
            echo \html_writer::tag('p',
                get_string('comparison_mismatches', 'quiz_paperentry', $cnt),
                ['class' => 'text-danger font-weight-bold']);
            echo $this->render_mismatch_table($result, false);
        }

        echo \html_writer::end_div();
        echo \html_writer::end_div();
    }


    /**
     * Render an alert listing questions that have shuffle answers enabled.
     *
     * No output is produced when $shuffled is empty.
     *
     * @param \stdClass[] $shuffled Questions with shuffle answers enabled.
     * @param \stdClass   $quiz     Quiz record (unused; kept for signature clarity).
     * @param \stdClass   $cm       Course-module record (used to build the quiz edit URL).
     * @return void
     */
    private function render_shuffle_warning(array $shuffled, \stdClass $quiz, $cm): void {
        global $OUTPUT;

        if (empty($shuffled)) {
            return;
        }

        $editurl = new \moodle_url('/mod/quiz/edit.php', ['cmid' => $cm->id]);
        $shuffledrows = [];
        foreach ($shuffled as $q) {
            $shuffledrows[] = ['text' => get_string('shufflewarning_question', 'quiz_paperentry',
                (object)['slot' => $q->slot, 'name' => s($q->name)])];
        }

        echo $OUTPUT->render_from_template('quiz_paperentry/shuffle_warning', [
            'editurl'  => $editurl->out(false),
            'shuffled' => $shuffledrows,
        ]);
    }

    /**
     * Render the Question Options Reference table.
     *
     * Correct answers are highlighted in green so graders can verify entries
     * without opening the quiz editor.
     *
     * When $remappings is non-empty each cell shows the saved substitution "from"
     * value (what graders actually type) alongside the raw answer text so graders
     * know exactly what to enter.
     *
     * @param \stdClass[]       $questions   Supported quiz questions with answers[] populated.
     * @param string            $collapseid  HTML id for the collapse wrapper.
     * @param bool              $collapsed   Whether the panel starts collapsed.
     * @param array<string,string> $remappings  Saved default substitutions (from→to) for this quiz.
     * @return void
     */
    private function render_question_reference(
        array $questions,
        string $collapseid = 'paperentry-qref',
        bool $collapsed = false,
        array $remappings = []
    ): void {
        global $OUTPUT;

        // Build reverse map: answer text (lowercase, stripped) → from-value.
        $reversemap = [];
        foreach ($remappings as $from => $to) {
            $key = strtolower(strip_tags((string)$to));
            $reversemap[$key] = (string)$from;
        }

        $maxanswers = 0;
        foreach ($questions as $q) {
            $maxanswers = max($maxanswers, count($q->answers));
        }

        // Build answer column headers (A, B, C, …).
        $headers = [];
        for ($i = 0; $i < $maxanswers; $i++) {
            $headers[] = ['letter' => chr(ord('A') + $i)];
        }

        // Build table rows.
        $correctlabel = get_string('correctanswer', 'quiz_paperentry');
        $blanklabel   = get_string('answerslotblank', 'quiz_paperentry');
        $rows = [];
        foreach ($questions as $q) {
            $cells = [];
            foreach ($q->answers as $ans) {
                $isbest   = $ans->fraction >= 1.0;
                $rawtext  = strip_tags($ans->answer);
                $fromval  = $reversemap[strtolower($rawtext)] ?? null;
                // Display the substitution shorthand prominently; show the full answer
                // text in parentheses so graders can cross-reference with the question.
                $display  = $fromval !== null
                    ? s($fromval) . ' <small class="text-muted">(' . s($rawtext) . ')</small>'
                    : s($rawtext);
                $cells[] = [
                    'text'      => $display,
                    'iscorrect' => $isbest,
                    'blank'     => false,
                    'label'     => $isbest ? $correctlabel : '',
                ];
            }
            for ($k = count($q->answers); $k < $maxanswers; $k++) {
                $cells[] = ['text' => $blanklabel, 'iscorrect' => false, 'blank' => true, 'label' => ''];
            }
            $rows[] = [
                'slot'     => $q->slot,
                'namehtml' => \html_writer::tag('strong', s($q->name)) . ' — ' .
                    format_text($q->questiontext, FORMAT_HTML, ['noclean' => false, 'para' => false]),
                'cells'    => $cells,
            ];
        }

        echo $OUTPUT->render_from_template('quiz_paperentry/question_reference', [
            'collapseid'     => $collapseid,
            'collapsed'      => $collapsed,
            'collapseclass'  => $collapsed ? 'collapse' : 'collapse show',
            'title'          => get_string('questionoptions', 'quiz_paperentry'),
            'questionheader' => get_string('questioncolheader', 'quiz_paperentry'),
            'headers'        => $headers,
            'rows'           => $rows,
        ]);
    }

    /**
     * Read the remap_from[] / remap_to[] POST arrays and return a map array.
     *
     * @return array<string,string>  Keyed by the raw cell value, value is the replacement.
     */
    private static function read_remapping_post(): array {
        $froms = optional_param_array('remap_from', [], PARAM_NOTAGS);
        $tos   = optional_param_array('remap_to',   [], PARAM_NOTAGS);
        $map   = [];
        foreach ($froms as $i => $from) {
            $from = trim((string)$from);
            $to   = trim((string)($tos[$i] ?? ''));
            if ($from !== '') {
                $map[$from] = $to;
            }
        }
        return $map;
    }

    /**
     * Render the value-substitution mapping widget.
     *
     * When $injectintoid is non-empty, the widget inputs live outside a form and
     * a small JS snippet copies them into the form with that element ID just
     * before submission (used for the Moodle override form).
     *
     * When $injectintoid is empty the widget must be placed inside the target
     * form element — inputs are submitted directly.
     *
     * @param string $widgetid     HTML id for the widget wrapper element.
     * @param bool   $injectintoid True when the widget is outside the target form.
     * @return void
     */
    private function render_mapping_widget(string $widgetid = 'pe-finalimport-map', bool $injectintoid = false): void {
        global $PAGE;

        echo \html_writer::start_div('mt-3 mb-2', ['id' => $widgetid]);
        echo \html_writer::tag('label',
            get_string('remap_label', 'quiz_paperentry'),
            ['class' => 'font-weight-bold d-block mb-1']);
        echo \html_writer::tag('small',
            get_string('remap_desc', 'quiz_paperentry'),
            ['class' => 'text-muted d-block mb-2']);
        echo \html_writer::tag('div', '', ['class' => 'pe-map-rows']);
        echo \html_writer::tag('button',
            get_string('remap_addpair', 'quiz_paperentry'),
            ['type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary pe-map-add mt-1']);
        echo \html_writer::end_div();

        $fromlabel = get_string('remap_from', 'quiz_paperentry');
        $tolabel   = get_string('remap_to', 'quiz_paperentry');

        // Initialise via AMD so the CSP nonce is applied automatically.
        $PAGE->requires->js_call_amd('quiz_paperentry/mapping_widget', 'init',
            [$widgetid, $fromlabel, $tolabel, $injectintoid]);
    }

    /**
     * Render the Edit Answer accordion — lets a manager change a single student's
     * answer for one question without re-uploading a full CSV.
     *
     * Renders two searchable <select> elements (student, question) and an answer
     * options panel that the JS layer populates on change.  The current answer is
     * highlighted so the manager can see what is already recorded before choosing
     * a replacement.
     *
     * @param \stdClass   $quiz      Quiz record.
     * @param \stdClass   $cm        Course-module record.
     * @param \stdClass[] $questions Supported quiz questions (with ->slot and ->answers).
     * @param \stdClass[] $students  Enrolled students keyed by userid.
     * @return void
     */
    private function render_edit_answer_section(
        \stdClass $quiz,
        $cm,
        array $questions,
        array $students
    ): void {
        global $PAGE;

        $collapseid = 'paperentry-editanswer-collapse';
        $formurl = new \moodle_url(
            '/mod/quiz/report.php',
            ['id' => $cm->id, 'mode' => 'paperentry', 'action' => 'editanswer']
        );

        // Load the current answer index for every student × slot.
        $currentanswers = export::get_student_attempt_answers($quiz, $questions);

        // Build JS-friendly question data: slot → {name, answers:[text,…]}.
        $qdata = [];
        foreach ($questions as $q) {
            $answerlist = [];
            foreach ($q->answers as $ans) {
                $answerlist[] = s(strip_tags($ans->answer));
            }
            $qdata[(int)$q->slot] = ['name' => s($q->name), 'answers' => $answerlist];
        }

        echo \html_writer::start_div('card mb-4 border-info');
        echo \html_writer::start_div('card-header bg-info text-white');
        echo \html_writer::tag('h5',
            \html_writer::tag('button', get_string('editanswer_section', 'quiz_paperentry'), [
                'class'          => 'btn btn-link collapsed text-white text-left w-100 p-0',
                'type'           => 'button',
                'data-bs-toggle' => 'collapse',
                'data-bs-target' => '#' . $collapseid,
                'aria-expanded'  => 'false',
                'aria-controls'  => $collapseid,
            ]),
            ['class' => 'mb-0']
        );
        echo \html_writer::end_div();
        echo \html_writer::start_div('collapse', ['id' => $collapseid]);
        echo \html_writer::start_div('card-body');

        echo \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $formurl->out(false),
            'id'     => 'pe-editanswer-form',
        ]);
        echo \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo \html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'ea_userid', 'id' => 'ea-userid-hidden', 'value' => '',
        ]);
        echo \html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'ea_slot', 'id' => 'ea-slot-hidden', 'value' => '',
        ]);
        echo \html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'ea_index', 'id' => 'ea-index-hidden', 'value' => '',
        ]);

        // Student select.
        echo \html_writer::start_div('form-group mb-3');
        echo \html_writer::tag('label',
            get_string('editanswer_student', 'quiz_paperentry'),
            ['for' => 'ea-student-select', 'class' => 'font-weight-bold']);
        $opts = \html_writer::tag('option', get_string('editanswer_pick_student', 'quiz_paperentry'), ['value' => '']);
        foreach ($students as $student) {
            $opts .= \html_writer::tag('option', s(fullname($student)), ['value' => $student->id]);
        }
        echo \html_writer::tag('select', $opts, [
            'id'    => 'ea-student-select',
            'class' => 'form-control',
            'style' => 'max-width:350px',
        ]);
        echo \html_writer::end_div();

        // Question select.
        echo \html_writer::start_div('form-group mb-3');
        echo \html_writer::tag('label',
            get_string('editanswer_question', 'quiz_paperentry'),
            ['for' => 'ea-question-select', 'class' => 'font-weight-bold']);
        $opts = \html_writer::tag('option', get_string('editanswer_pick_question', 'quiz_paperentry'), ['value' => '']);
        foreach ($questions as $q) {
            $opts .= \html_writer::tag('option', 'Q' . $q->slot . ': ' . s($q->name), ['value' => $q->slot]);
        }
        echo \html_writer::tag('select', $opts, [
            'id'    => 'ea-question-select',
            'class' => 'form-control',
            'style' => 'max-width:350px',
        ]);
        echo \html_writer::end_div();

        // Answer options area (populated by JS on student/question change).
        echo \html_writer::tag('div', '', ['id' => 'ea-answers-area', 'class' => 'mb-3']);

        echo \html_writer::empty_tag('input', [
            'type'     => 'submit',
            'class'    => 'btn btn-warning',
            'id'       => 'ea-submit-btn',
            'value'    => get_string('editanswer_button', 'quiz_paperentry'),
            'disabled' => 'disabled',
        ]);

        echo \html_writer::end_tag('form');
        echo \html_writer::end_div(); // Card body.
        echo \html_writer::end_div(); // Collapse wrapper.
        echo \html_writer::end_div(); // Card.

        // Initialise via AMD so the CSP nonce is applied automatically.
        $PAGE->requires->js_call_amd('quiz_paperentry/edit_answer', 'init', [
            $qdata,
            $currentanswers,
            get_string('editanswer_noattempt', 'quiz_paperentry'),
            get_string('editanswer_current',   'quiz_paperentry'),
        ]);
    }

    /**
     * Render import result notifications (errors, success count, skipped count, warnings).
     *
     * @param \stdClass $r Object with properties: errors[], imported (int), skipped (int), warnings[].
     * @return void
     */
    private function render_import_results(\stdClass $r): void {
        global $OUTPUT;

        $erroritems   = array_map(fn($e) => ['message' => $e], $r->errors ?? []);
        $warningitems = array_map(fn($w) => ['message' => $w], $r->warnings ?? []);

        echo $OUTPUT->render_from_template('quiz_paperentry/import_results', [
            'errors'      => $erroritems,
            'imported'    => $r->imported,
            'importedmsg' => $r->imported > 0
                ? get_string('importok', 'quiz_paperentry', $r->imported) : '',
            'hasimported' => $r->imported > 0,
            'skipped'     => $r->skipped,
            'skippedmsg'  => $r->skipped > 0
                ? get_string('importskipped', 'quiz_paperentry', $r->skipped) : '',
            'hasskipped'  => $r->skipped > 0,
            'warnings'    => $warningitems,
            'haswarnings' => !empty($warningitems),
        ]);
    }
}
