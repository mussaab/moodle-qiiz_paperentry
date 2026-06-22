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
 * CSV export helper for quiz_paperentry.
 *
 * @package     quiz_paperentry
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_paperentry;

/**
 * Builds and streams a CSV answer sheet for a quiz.
 *
 * Column order: userid, firstname, lastname, [extra profile fields…], [question_name...]
 * Answer cells are empty — teachers fill in the answer text for each student.
 *
 * Supported question types: multichoice, truefalse.
 */
class export {

    /**
     * Stream a CSV to the browser and exit.
     *
     * Reads optional GET param extrafields[] to include extra user profile
     * columns between the identity columns and the question columns.
     *
     * @param \stdClass $quiz
     * @param \stdClass $cm
     * @param \stdClass $course
     */
    public static function send_csv(\stdClass $quiz, $cm, \stdClass $course): void {
        // Read extra profile fields from DB settings (already validated when saved).
        $extrafields = settings_manager::get_extra_fields($quiz->id);

        $questions = self::get_questions($quiz);
        $students  = self::get_enrolled_students($cm, $course);

        // Pre-load custom profile field data for all students in one query.
        $customdata = [];
        $hascustom = !empty(array_filter($extrafields, fn($f) => str_starts_with($f, 'profile_field_')));
        if ($hascustom) {
            $customdata = self::load_custom_profile_fields(array_keys($students));
        }

        $filename = clean_filename($course->shortname . '_' . $quiz->name . '_answers.csv');

        // Release the session lock so the browser is not blocked during download.
        \core\session\manager::write_close();

        // Discard any buffered output that would corrupt the binary stream.
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');

        $out = fopen('php://output', 'w');

        // UTF-8 BOM so Excel opens it correctly.
        fwrite($out, "\xEF\xBB\xBF");

        // Header row: identifiers + extra profile field labels + question names.
        $available = self::get_available_profile_fields();
        $header = ['userid', 'firstname', 'lastname'];
        foreach ($extrafields as $field) {
            $header[] = $available[$field] ?? $field;
        }
        $seennames = [];
        foreach ($questions as $q) {
            $colname = $q->name;
            if (isset($seennames[$colname])) {
                $colname = $q->name . ' (' . $q->slot . ')';
            }
            $seennames[$q->name] = true;
            $header[] = self::csv_safe($colname);
        }
        fputcsv($out, $header);

        // One row per student — extra field values filled in, answer cells blank.
        foreach ($students as $student) {
            $row = [
                $student->id,
                self::csv_safe($student->firstname),
                self::csv_safe($student->lastname),
            ];

            foreach ($extrafields as $field) {
                if (str_starts_with($field, 'profile_field_')) {
                    $shortname = substr($field, strlen('profile_field_'));
                    $row[] = self::csv_safe($customdata[$student->id][$shortname] ?? '');
                } else {
                    $row[] = self::csv_safe($student->$field ?? '');
                }
            }

            foreach ($questions as $q) {
                $row[] = ''; // Teacher fills this in.
            }

            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /**
     * Return all user profile fields available for export, keyed by field identifier.
     *
     * Includes standard Moodle user fields (email, idnumber, …) and any custom
     * profile fields defined in user_info_field.
     *
     * @return array<string,string>  fieldkey => human label
     */
    public static function get_available_profile_fields(): array {
        global $DB;

        $fields = [
            'email'       => get_string('email'),
            'idnumber'    => get_string('idnumber'),
            'phone1'      => get_string('phone1'),
            'city'        => get_string('city'),
            'country'     => get_string('country'),
            'institution' => get_string('institution'),
            'department'  => get_string('department'),
        ];

        $customfields = $DB->get_records('user_info_field', null, 'sortorder ASC', 'shortname, name');
        foreach ($customfields as $f) {
            $fields['profile_field_' . $f->shortname] = $f->name;
        }

        return $fields;
    }

    /**
     * Return multichoice questions that have shuffle answers enabled.
     * These must be fixed before the answer sheet can be exported.
     *
     * @param \stdClass $quiz
     * @return \stdClass[]  Question records (id, name, slot) with shuffleanswers=1.
     */
    public static function get_shuffled_questions(\stdClass $quiz): array {
        global $DB;

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot ASC');
        if (empty($slots)) {
            return [];
        }

        $shuffled = [];
        foreach ($slots as $slot) {
            $questionid = self::resolve_question_id($slot);
            if (!$questionid) {
                continue;
            }
            $q = $DB->get_record('question', ['id' => $questionid]);
            if (!$q || $q->qtype !== 'multichoice') {
                continue;
            }
            $opts = $DB->get_record('qtype_multichoice_options', ['questionid' => $q->id]);
            if ($opts && !empty($opts->shuffleanswers)) {
                $q->slot = $slot->slot;
                $shuffled[] = $q;
            }
        }
        return $shuffled;
    }

    /**
     * Return ordered answerable questions for this quiz.
     *
     * Includes multichoice and truefalse — both map cleanly to letter-based
     * answers on a paper answer sheet. Other question types (essay, shortanswer,
     * matching, numerical) are excluded as they cannot be answered with a single
     * option choice.
     *
     * Each returned object has: id, name, slot, qtype, answers[].
     * answers[] is ordered by DB id ascending (= paper print order).
     *
     * @param \stdClass $quiz
     * @return \stdClass[]
     */
    public static function get_questions(\stdClass $quiz): array {
        global $DB;

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot ASC');
        if (empty($slots)) {
            return [];
        }

        $supported = ['multichoice', 'truefalse'];
        $questions = [];

        foreach ($slots as $slot) {
            $questionid = self::resolve_question_id($slot);
            if (!$questionid) {
                continue;
            }

            $q = $DB->get_record('question', ['id' => $questionid]);
            if (!$q || !in_array($q->qtype, $supported, true)) {
                continue;
            }

            $answers = $DB->get_records('question_answers',
                ['question' => $q->id], 'id ASC', 'id, answer, fraction');

            $q->slot    = $slot->slot;
            $q->answers = array_values($answers);
            $questions[] = $q;
        }

        return $questions;
    }

    /**
     * Return all enrolled students (those with quiz:attempt capability).
     * Fetches all standard user fields so extras can be included in exports
     * without an additional query.
     *
     * @param \stdClass $cm
     * @param \stdClass $course
     * @return \stdClass[]  Keyed by userid.
     */
    public static function get_enrolled_students($cm, \stdClass $course): array {
        global $DB;

        $context       = \context_module::instance($cm->id);
        $coursecontext = \context_course::instance($course->id);

        // Collect IDs of every role whose archetype is 'student'.
        $studentroleids = array_keys(get_archetype_roles('student'));
        if (empty($studentroleids)) {
            return [];
        }

        // Find userids that have been assigned a student-archetype role in the
        // course context (role assignments at module level are rare and ignored
        // intentionally — course-level enrolment is the canonical source).
        [$insql, $inparams] = $DB->get_in_or_equal($studentroleids, SQL_PARAMS_NAMED, 'role');
        $inparams['ctxid'] = $coursecontext->id;
        $studentids = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid
               FROM {role_assignments}
              WHERE roleid $insql
                AND contextid = :ctxid",
            $inparams
        );

        if (empty($studentids)) {
            return [];
        }

        // Fetch full user records for those students, sorted for the CSV output.
        [$useridssql, $useridsparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'uid');
        $fields = 'u.id, u.firstname, u.lastname, u.email, u.idnumber,
                   u.phone1, u.city, u.country, u.institution, u.department';
        $users = $DB->get_records_sql(
            "SELECT $fields
               FROM {user} u
              WHERE u.id $useridssql
              ORDER BY u.lastname ASC, u.firstname ASC",
            $useridsparams
        );

        return $users;
    }

    /**
     * Return the current answer index (0-based) for each student/question pair,
     * reading from the student's most recent finished attempt.
     *
     * Used by the Edit Answer widget to pre-select the student's current answer.
     * The returned structure is:
     *   [ userid => [ slot => answerindex|null, ... ], ... ]
     *
     * A value of null means the question was not answered (or no attempt exists).
     *
     * @param \stdClass   $quiz      Quiz record.
     * @param \stdClass[] $questions Questions returned by get_questions(), with ->slot and ->answers.
     * @return array<int, array<int, int|null>>
     */
    public static function get_student_attempt_answers(\stdClass $quiz, array $questions): array {
        global $DB;

        if (empty($questions)) {
            return [];
        }

        // Get the latest finished attempt per student.
        $attempts = $DB->get_records_sql(
            "SELECT qa.userid, qa.id AS attemptid, qa.uniqueid
               FROM {quiz_attempts} qa
               JOIN (
                   SELECT userid, MAX(id) AS maxid
                     FROM {quiz_attempts}
                    WHERE quiz = :qid AND state = 'finished'
                    GROUP BY userid
               ) latest ON latest.maxid = qa.id",
            ['qid' => $quiz->id]
        );

        if (empty($attempts)) {
            return [];
        }

        $result = [];
        foreach ($attempts as $attempt) {
            $uid = (int)$attempt->userid;
            $result[$uid] = [];

            $quba = \question_engine::load_questions_usage_by_activity((int)$attempt->uniqueid);

            foreach ($questions as $q) {
                $qa  = $quba->get_question_attempt((int)$q->slot);
                $raw = $qa->get_last_qt_data()['answer'] ?? null;

                if ($raw === null) {
                    $result[$uid][(int)$q->slot] = null;
                } else if ($q->qtype === 'truefalse') {
                    // Stored value is the answer DB id; convert to 0-based index.
                    $result[$uid][(int)$q->slot] = null;
                    foreach ($q->answers as $i => $ans) {
                        if ((string)$ans->id === (string)$raw) {
                            $result[$uid][(int)$q->slot] = $i;
                            break;
                        }
                    }
                } else {
                    // Multichoice: stored value is the 0-based order index.
                    $result[$uid][(int)$q->slot] = (int)$raw;
                }
            }
        }

        return $result;
    }

    /**
     * Prefix cell values starting with formula-trigger characters to prevent
     * CSV injection when the file is opened in Excel or LibreOffice Calc.
     *
     * @param string $value Raw cell value.
     * @return string Safe cell value (prefixed with a single quote when necessary).
     */
    public static function csv_safe(string $value): string {
        if ($value !== '' && strpos('=+-@|%', $value[0]) !== false) {
            return "'" . $value;
        }
        return $value;
    }

    // Private helpers.

    /**
     * Load custom profile field values for a set of users in a single query.
     *
     * @param int[] $userids
     * @return array<int, array<string,string>>  userid → [shortname => value]
     */
    private static function load_custom_profile_fields(array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT uid.id, uid.userid, uif.shortname, uid.data
               FROM {user_info_data} uid
               JOIN {user_info_field} uif ON uif.id = uid.fieldid
              WHERE uid.userid $insql",
            $params
        );

        $result = [];
        foreach ($records as $r) {
            $result[(int)$r->userid][$r->shortname] = $r->data;
        }
        return $result;
    }

    /**
     * Resolve the actual question id from a quiz_slots row.
     * Moodle 4.0+ uses question_bank_entries; earlier versions used questionid directly.
     */
    private static function resolve_question_id(\stdClass $slot): ?int {
        global $DB;

        if (!empty($slot->questionid)) {
            return (int) $slot->questionid;
        }

        $ref = $DB->get_record('question_references', [
            'component'    => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid'       => $slot->id,
        ]);
        if ($ref && !empty($ref->questionbankentryid)) {
            $rows = $DB->get_records_sql(
                'SELECT qv.questionid FROM {question_versions} qv
                  WHERE qv.questionbankentryid = ?
                  ORDER BY qv.version DESC',
                [$ref->questionbankentryid], 0, 1
            );
            $version = reset($rows) ?: null;
            if ($version) {
                return (int) $version->questionid;
            }
        }

        return null;
    }
}
