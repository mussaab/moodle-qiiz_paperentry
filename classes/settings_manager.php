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
 * Settings manager for quiz_paperentry — handles per-quiz configuration,
 * grader roster, and grader CSV submissions.
 *
 * @package     quiz_paperentry
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_paperentry;

/**
 * Static helpers for reading/writing quiz_paperentry_settings,
 * quiz_paperentry_graders, and quiz_paperentry_submissions.
 */
class settings_manager {

    // Extra fields.

    /**
     * Return the saved extra profile field keys for a quiz.
     *
     * @param int $quizid
     * @return string[]
     */
    public static function get_extra_fields(int $quizid): array {
        global $DB;
        $row = $DB->get_record('quiz_paperentry_settings', ['quizid' => $quizid]);
        if (!$row || empty($row->extrafields)) {
            return [];
        }
        $decoded = json_decode($row->extrafields, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist the extra profile field keys for a quiz.
     *
     * @param int    $quizid
     * @param array  $fields  Array of field key strings.
     */
    public static function save_extra_fields(int $quizid, array $fields): void {
        global $DB;
        $now = time();
        $existing = $DB->get_record('quiz_paperentry_settings', ['quizid' => $quizid]);
        if ($existing) {
            $existing->extrafields  = json_encode(array_values($fields));
            $existing->timemodified = $now;
            $DB->update_record('quiz_paperentry_settings', $existing);
        } else {
            $row = new \stdClass();
            $row->quizid       = $quizid;
            $row->extrafields  = json_encode(array_values($fields));
            $row->timecreated  = $now;
            $row->timemodified = $now;
            $DB->insert_record('quiz_paperentry_settings', $row);
        }
    }

    // Remappings (default value substitutions).

    /**
     * Return the saved default remappings for a quiz as a from→to array.
     *
     * @param int $quizid
     * @return array<string,string>  Keys are grader-typed values, values are answer texts.
     */
    public static function get_remappings(int $quizid): array {
        global $DB;
        $row = $DB->get_record('quiz_paperentry_settings', ['quizid' => $quizid]);
        if (!$row || empty($row->remappings)) {
            return [];
        }
        $decoded = json_decode($row->remappings, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist default value substitutions for a quiz.
     *
     * @param int               $quizid
     * @param array<string,string> $remappings  Keys are grader-typed values, values are answer texts.
     */
    public static function save_remappings(int $quizid, array $remappings): void {
        global $DB;
        $now      = time();
        $existing = $DB->get_record('quiz_paperentry_settings', ['quizid' => $quizid]);
        if ($existing) {
            $existing->remappings   = json_encode($remappings);
            $existing->timemodified = $now;
            $DB->update_record('quiz_paperentry_settings', $existing);
        } else {
            $row = new \stdClass();
            $row->quizid       = $quizid;
            $row->extrafields  = null;
            $row->remappings   = json_encode($remappings);
            $row->timecreated  = $now;
            $row->timemodified = $now;
            $DB->insert_record('quiz_paperentry_settings', $row);
        }
    }

    // Graders.

    /**
     * Return all graders for the given quiz as user objects keyed by userid.
     *
     * @param int $quizid
     * @return \stdClass[]  keyed by userid
     */
    public static function get_graders(int $quizid): array {
        global $DB;
        $rows = $DB->get_records('quiz_paperentry_graders', ['quizid' => $quizid]);
        if (empty($rows)) {
            return [];
        }
        $userids = array_column((array)$rows, 'userid');
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $users = $DB->get_records_sql(
            "SELECT id, firstname, lastname, email FROM {user} WHERE id $insql", $params);
        return $users; // Keyed by userid.
    }

    /**
     * Add a user as a grader for the given quiz (idempotent).
     *
     * Records the assignment in the graders table AND grants the
     * quiz/paperentry:submit capability at the module context so that
     * Moodle's standard has_capability() check controls access.
     *
     * @param int              $quizid
     * @param int              $userid
     * @param \context_module  $context Module context for the quiz.
     */
    public static function add_grader(int $quizid, int $userid, \context_module $context): void {
        global $DB;
        if (!$DB->record_exists('quiz_paperentry_graders', ['quizid' => $quizid, 'userid' => $userid])) {
            $row = new \stdClass();
            $row->quizid      = $quizid;
            $row->userid      = $userid;
            $row->timecreated = time();
            $DB->insert_record('quiz_paperentry_graders', $row);
        }
        // Grant the submit capability at this module context for this user.
        $submitcap = 'quiz/paperentry:submit';
        $roleid    = self::get_or_create_grader_role();
        role_assign($roleid, $userid, $context->id);
        // Ensure the capability is allowed on the role (idempotent).
        assign_capability($submitcap, CAP_ALLOW, $roleid, $context->id, true);
    }

    /**
     * Remove a user as a grader for the given quiz.
     *
     * Deletes the grader record and revokes the quiz/paperentry:submit
     * capability at the module context.
     *
     * @param int              $quizid
     * @param int              $userid
     * @param \context_module  $context Module context for the quiz.
     */
    public static function remove_grader(int $quizid, int $userid, \context_module $context): void {
        global $DB;
        $DB->delete_records('quiz_paperentry_graders', ['quizid' => $quizid, 'userid' => $userid]);
        // Also delete their submission tracking record and stored file.
        $DB->delete_records('quiz_paperentry_submissions', ['quizid' => $quizid, 'userid' => $userid]);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, self::FILE_COMPONENT, self::FILE_AREA, $userid);
        // Revoke the submit capability at this module context for this user.
        $roleid = self::get_or_create_grader_role();
        role_unassign($roleid, $userid, $context->id);
    }

    /**
     * Check whether a given user is recorded as a grader for the quiz.
     *
     * Used for display purposes (e.g. showing submission status).
     * Access control uses has_capability('quiz/paperentry:submit') instead.
     *
     * @param int $quizid
     * @param int $userid
     * @return bool
     */
    public static function is_grader(int $quizid, int $userid): bool {
        global $DB;
        return $DB->record_exists('quiz_paperentry_graders', ['quizid' => $quizid, 'userid' => $userid]);
    }

    /**
     * Return the id of the internal "paperentry_grader" role, creating it if needed.
     *
     * This lightweight role exists solely to carry the quiz/paperentry:submit
     * capability at the module context level for designated graders.
     *
     * @return int Role id.
     */
    private static function get_or_create_grader_role(): int {
        global $DB;
        $shortname = 'paperentry_grader';
        $role = $DB->get_record('role', ['shortname' => $shortname], 'id');
        if ($role) {
            return (int)$role->id;
        }
        $roleid = create_role(
            get_string('graderrole_name', 'quiz_paperentry'),
            $shortname,
            get_string('graderrole_desc', 'quiz_paperentry'),
            '' // No archetype — purely internal.
        );
        // Allow this role to be assigned only at module context.
        set_role_contextlevels($roleid, [CONTEXT_MODULE]);
        return $roleid;
    }

    // Submissions.

    // File API constants: all submission files live under the module context
    // so they are automatically removed when the quiz activity is deleted.
    /** @var string Moodle component name for file storage. */
    const FILE_COMPONENT = 'quiz_paperentry';
    /** @var string File area for grader CSV submissions. */
    const FILE_AREA = 'submission';
    /** @var string Standard filename used for every submission. */
    const FILE_NAME = 'submission.csv';

    /**
     * Save (insert or update) a grader's CSV submission.
     *
     * The CSV content is stored via the Moodle File API at the module context
     * so it lives alongside all other activity files and is automatically
     * included in course backups and privacy exports.
     *
     * @param int              $quizid
     * @param int              $userid
     * @param string           $csvdata  Raw CSV string from the uploaded file.
     * @param \context_module  $context  Module context for the quiz activity.
     */
    public static function save_submission(int $quizid, int $userid, string $csvdata,
            \context_module $context): void {
        global $DB;

        $now = time();
        $fs  = get_file_storage();

        // Replace any existing file for this grader in this quiz context.
        $fs->delete_area_files($context->id, self::FILE_COMPONENT, self::FILE_AREA, $userid);
        $fs->create_file_from_string([
            'contextid'    => $context->id,
            'component'    => self::FILE_COMPONENT,
            'filearea'     => self::FILE_AREA,
            'itemid'       => $userid,
            'filepath'     => '/',
            'filename'     => self::FILE_NAME,
            'userid'       => $userid,
            'timecreated'  => $now,
            'timemodified' => $now,
        ], $csvdata);

        // Update the tracking record (timestamp only — no csvdata column).
        $existing = $DB->get_record('quiz_paperentry_submissions',
            ['quizid' => $quizid, 'userid' => $userid]);
        if ($existing) {
            $existing->timemodified = $now;
            $DB->update_record('quiz_paperentry_submissions', $existing);
        } else {
            $row = new \stdClass();
            $row->quizid       = $quizid;
            $row->userid       = $userid;
            $row->timecreated  = $now;
            $row->timemodified = $now;
            $DB->insert_record('quiz_paperentry_submissions', $row);
        }
    }

    /**
     * Get a single grader's submission tracking record, or null if none.
     *
     * To get the CSV content use get_submission_file() instead.
     *
     * @param int $quizid
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get_submission(int $quizid, int $userid): ?\stdClass {
        global $DB;
        $row = $DB->get_record('quiz_paperentry_submissions', ['quizid' => $quizid, 'userid' => $userid]);
        return $row ?: null;
    }

    /**
     * Return the stored_file for a grader's CSV submission, or null if not yet uploaded.
     *
     * @param int              $userid
     * @param \context_module  $context Module context for the quiz.
     * @return \stored_file|null
     */
    public static function get_submission_file(int $userid, \context_module $context): ?\stored_file {
        $fs   = get_file_storage();
        $file = $fs->get_file($context->id, self::FILE_COMPONENT, self::FILE_AREA,
            $userid, '/', self::FILE_NAME);
        return ($file && !$file->is_directory()) ? $file : null;
    }

    /**
     * Get all grader submissions for a quiz, keyed by userid.
     *
     * Returns only the lightweight tracking records (no file content).
     * Use get_all_submissions_csv() when you need the CSV content for comparison.
     *
     * @param int $quizid
     * @return \stdClass[]  Keyed by userid.
     */
    public static function get_all_submissions(int $quizid): array {
        global $DB;
        $rows = $DB->get_records('quiz_paperentry_submissions', ['quizid' => $quizid]);
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->userid] = $row;
        }
        return $result;
    }

    /**
     * Return a map of userid → CSV string for all submitted graders.
     *
     * Reads files from the Moodle File API. Entries with a missing file are
     * silently skipped (should not happen in normal operation).
     *
     * @param int              $quizid
     * @param \context_module  $context Module context for the quiz.
     * @return array<int,string>  Keyed by userid.
     */
    public static function get_all_submissions_csv(int $quizid, \context_module $context): array {
        $submissions = self::get_all_submissions($quizid);
        $fs  = get_file_storage();
        $map = [];
        foreach ($submissions as $userid => $sub) {
            $file = $fs->get_file($context->id, self::FILE_COMPONENT, self::FILE_AREA,
                $userid, '/', self::FILE_NAME);
            if ($file && !$file->is_directory()) {
                $map[(int)$userid] = $file->get_content();
            }
        }
        return $map;
    }

    /**
     * Return true only when there is at least one grader AND every grader
     * has uploaded a submission.
     *
     * @param int $quizid
     * @return bool
     */
    public static function has_all_graders_submitted(int $quizid): bool {
        global $DB;
        $gradercount = $DB->count_records('quiz_paperentry_graders', ['quizid' => $quizid]);
        if ($gradercount === 0) {
            return false;
        }
        $submissioncount = $DB->count_records('quiz_paperentry_submissions', ['quizid' => $quizid]);
        return $submissioncount >= $gradercount;
    }

    // Potential graders.

    /**
     * Return all enrolled users for the course module as potential graders,
     * keyed by userid.
     *
     * @param \stdClass $cm
     * @param \stdClass $course
     * @return \stdClass[]  keyed by userid
     */
    public static function get_potential_graders($cm, \stdClass $course): array {
        $context = \context_module::instance($cm->id);
        $users = get_enrolled_users($context, '', 0,
            'u.id, u.firstname, u.lastname, u.email',
            'u.lastname ASC, u.firstname ASC');
        return $users;
    }
}
