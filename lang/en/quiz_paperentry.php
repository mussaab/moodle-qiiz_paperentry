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
 * Language strings for quiz_paperentry.
 *
 * @package     quiz_paperentry
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addgrader'] = 'Add grader';
$string['addgrader_pick'] = '— select a grader —';
$string['answerslotblank'] = '—';
$string['comparison_all_match'] = 'All graders agree on all answers. ✓';
$string['comparison_mismatches'] = '{$a} mismatch(es) found. Resolve these before submitting.';
$string['correctanswer'] = 'Correct answer';
$string['editanswer_button'] = 'Override Answer';
$string['editanswer_current'] = '(current answer)';
$string['editanswer_noattempt'] = 'This student has no finished attempt yet.';
$string['editanswer_pick_question'] = '— select a question —';
$string['editanswer_pick_student'] = '— select a student —';
$string['editanswer_question'] = 'Question';
$string['editanswer_section'] = 'Edit Answer';
$string['editanswer_student'] = 'Student';
$string['editanswer_success'] = 'Answer updated successfully.';
$string['exportcsv'] = 'Export answer sheet (CSV)';
$string['exportdesc'] = 'Download a CSV with one row per enrolled student and one column per question. Write the answer text exactly as it appears in the question options table below, then re-upload.';
$string['exportfields_label'] = 'Additional profile fields';
$string['exportfields_placeholder'] = '— add a field —';
$string['finalimport_confirm'] = 'All graders agree. Creating attempts...';
$string['finalimport_done'] = 'Final import complete: {$a} attempt(s) created.';
$string['grader_added'] = 'Grader added.';
$string['grader_comparison_section'] = 'Comparison with Other Graders';
$string['grader_comparison_waiting'] = 'Waiting for other graders to submit before comparison can be shown.';
$string['grader_export_desc'] = 'Download the blank answer sheet configured by your administrator. Fill in each student\'s answers, then upload below.';
$string['grader_export_section'] = 'Download Answer Sheet';
$string['grader_last_submitted'] = 'Last submitted: {$a}';
$string['grader_pending'] = 'Pending';
$string['grader_removed'] = 'Grader removed.';
$string['grader_submit_desc'] = 'Upload your completed answer sheet. Your previous submission (if any) will be replaced.';
$string['grader_submit_section'] = 'Submit Your Answers';
$string['grader_submitted'] = 'Submitted';
$string['graderrole_desc'] = 'Internal role assigned to users designated as graders for a specific Paper Entry quiz. Carries the quiz/paperentry:submit capability at the module context level.';
$string['graderrole_name'] = 'Paper Entry Grader';
$string['graders_desc'] = 'Graders download the answer sheet, fill it in independently, then upload it here. When all graders agree, you can submit to the gradebook.';
$string['graders_none'] = 'No graders assigned. Use the form below to add graders.';
$string['graders_section'] = 'Graders';
$string['gradersubmit_success'] = 'Your answer sheet has been saved.';
$string['importcsv'] = 'Import filled answer sheet';
$string['importdesc'] = 'Upload the CSV you exported and filled in. Each student\'s answers will be saved as a graded attempt. Any attempt previously created by this tool will be replaced; manually-created attempts are not affected.';
$string['importerror_attempt'] = 'Row {$a->row}: could not create attempt for user {$a->userid} — {$a->error}';
$string['importerror_badanswer'] = 'Row {$a->row}: "{$a->answer}" does not match any answer option for question "{$a->question}". Valid options are: {$a->options}.';
$string['importerror_header'] = 'The uploaded file has different columns than this quiz. Please re-export and try again.';
$string['importerror_nofile'] = 'No file was uploaded.';
$string['importerror_notcsv'] = 'The uploaded file is not a CSV. Please upload a .csv file.';
$string['importerror_nouser'] = 'Row {$a}: user ID not found or not enrolled.';
$string['importerror_parse'] = 'Could not read the CSV file.';
$string['importerror_toobig'] = 'The uploaded file is too large (maximum {$a}).';
$string['importfile'] = 'CSV file';
$string['importok'] = 'Successfully imported {$a} attempt(s).';
$string['importresults'] = 'Import results';
$string['importskipped'] = 'Skipped {$a} row(s) — see details below.';
$string['importsubmit'] = 'Import and grade';
$string['mismatch_col_grader'] = 'Grader {$a}';
$string['mismatch_col_question'] = 'Question';
$string['mismatch_col_student'] = 'Student';
$string['noaccess'] = 'You do not have access to this report. Contact your course administrator if you believe this is an error.';
$string['noavailablegraders'] = 'All enrolled participants are already graders.';
$string['noquestions'] = 'This quiz has no supported questions (multiple-choice or true/false).';
$string['nostudents'] = 'No students are enrolled in this course.';
$string['override_desc'] = 'Upload a filled CSV directly, bypassing the grader comparison workflow. Use only in emergencies.';
$string['override_section'] = 'Direct Import — Admin Override';
$string['override_warning'] = 'Warning: This action immediately deletes existing attempts and creates new ones without grader verification.';
$string['paperentry'] = 'Paper Entry';
$string['paperentry:import'] = 'Import filled answer sheets and create graded attempts';
$string['paperentry:manage'] = 'Manage Paper Entry: configure settings, manage graders, run comparison, and import';
$string['paperentry:submit'] = 'Submit grader answer sheet (upload CSV)';
$string['paperentry:view'] = 'View Paper Entry report and export blank answer sheets';
$string['pluginname'] = 'Paper Entry';
$string['privacy:metadata:attemptlog'] = 'Records which quiz attempts were created by the Paper Entry import tool, so that re-imports only replace those attempts and not manually-graded ones.';
$string['privacy:metadata:attemptlog:attemptid'] = 'The ID of the quiz attempt that was created.';
$string['privacy:metadata:attemptlog:quizid'] = 'The quiz the attempt belongs to.';
$string['privacy:metadata:attemptlog:timecreated'] = 'The time the attempt was imported.';
$string['privacy:metadata:attemptlog:userid'] = 'The ID of the student whose attempt was imported.';
$string['privacy:metadata:graders'] = 'Records which users have been assigned as graders for a quiz by the Paper Entry tool.';
$string['privacy:metadata:graders:quizid'] = 'The quiz this grader assignment belongs to.';
$string['privacy:metadata:graders:timecreated'] = 'The time the grader was assigned.';
$string['privacy:metadata:graders:userid'] = 'The ID of the user assigned as a grader.';
$string['privacy:metadata:submissionfiles'] = 'The filled answer-sheet CSV file submitted by the grader, stored via the Moodle File API.';
$string['privacy:metadata:submissions'] = 'Stores the filled answer-sheet CSV submitted by each grader. The CSV contains student answer data entered by the grader.';
$string['privacy:metadata:submissions:quizid'] = 'The quiz this submission belongs to.';
$string['privacy:metadata:submissions:timecreated'] = 'The time the submission was first created.';
$string['privacy:metadata:submissions:timemodified'] = 'The time the submission was last updated.';
$string['privacy:metadata:submissions:userid'] = 'The ID of the grader who submitted the answer sheet.';
$string['questioncolheader'] = 'Question';
$string['questionoptions'] = 'Question options reference';
$string['questionoptionsdesc'] = 'Use this table to see which letter corresponds to which answer option for each question.';
$string['remap_addpair'] = '+ Add substitution';
$string['remap_default_desc'] = 'Save shorthand substitutions that apply to this quiz. Graders can type these short values in their answer sheet instead of the full answer text. These are shown on the grader screen as a reference.';
$string['remap_default_label'] = 'Default value substitutions';
$string['remap_desc'] = 'If graders used shorthand values that differ from the default (e.g. "1" instead of "a"), add substitutions here. Each value will be replaced before matching.';
$string['remap_from'] = 'Grader wrote';
$string['remap_label'] = 'Value substitutions (optional)';
$string['remap_to'] = 'Treat as';
$string['replacewarning'] = 'Attempts previously created by this tool will be deleted and replaced with the imported data.';
$string['savesettings'] = 'Save settings';
$string['settings_desc'] = 'Select which student profile fields to include in exported answer sheets. These settings apply to all graders.';
$string['settings_saved'] = 'Settings saved.';
$string['settings_section'] = 'Export Settings';
$string['shufflewarning_intro'] = 'The following question(s) have "Shuffle answers" enabled. Because answer order is randomised per attempt, letter labels (A/B/C/D) on the paper sheet would be meaningless. Disable shuffling in the quiz settings for each question below, then return here to export.';
$string['shufflewarning_link'] = 'Go to quiz question settings';
$string['shufflewarning_question'] = 'Q{$a->slot}: {$a->name}';
$string['shufflewarning_title'] = 'Shuffle answers must be disabled before exporting';
$string['skipreason_noanswers'] = 'all answers blank — skipped';
$string['submissions_none_graders'] = 'No graders configured. Add graders above before proceeding.';
$string['submissions_section'] = 'Grader Submissions & Comparison';
$string['submissions_waiting'] = '{$a->submitted} of {$a->total} grader(s) have submitted.';
$string['submit_disabled_mismatches'] = 'Cannot submit: there are unresolved answer mismatches.';
$string['submit_disabled_pending'] = 'Cannot submit: not all graders have uploaded their sheets.';
$string['submit_final'] = 'Submit to Gradebook';
$string['submit_final_desc'] = 'All graders agree. Click to create graded attempts for all students.';
