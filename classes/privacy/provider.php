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
 * Privacy provider for quiz_paperentry.
 *
 * @package     quiz_paperentry
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_paperentry\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider — describes and manages all personal data stored by this plugin.
 *
 * Three plugin tables contain user data:
 *  - quiz_paperentry_attempts    — records which quiz attempts were created by the import tool.
 *  - quiz_paperentry_graders     — records which users are designated as graders.
 *  - quiz_paperentry_submissions — stores the filled CSV answer-sheet uploaded by each grader.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Return metadata about the personal data this plugin stores.
     *
     * @param collection $collection Metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table('quiz_paperentry_attempts', [
            'attemptid'   => 'privacy:metadata:attemptlog:attemptid',
            'quizid'      => 'privacy:metadata:attemptlog:quizid',
            'userid'      => 'privacy:metadata:attemptlog:userid',
            'timecreated' => 'privacy:metadata:attemptlog:timecreated',
        ], 'privacy:metadata:attemptlog');

        $collection->add_database_table('quiz_paperentry_graders', [
            'quizid'      => 'privacy:metadata:graders:quizid',
            'userid'      => 'privacy:metadata:graders:userid',
            'timecreated' => 'privacy:metadata:graders:timecreated',
        ], 'privacy:metadata:graders');

        $collection->add_database_table('quiz_paperentry_submissions', [
            'quizid'       => 'privacy:metadata:submissions:quizid',
            'userid'       => 'privacy:metadata:submissions:userid',
            'timecreated'  => 'privacy:metadata:submissions:timecreated',
            'timemodified' => 'privacy:metadata:submissions:timemodified',
        ], 'privacy:metadata:submissions');

        // CSV files are stored via the Moodle File API.
        $collection->add_pluginfile_files(
            'quiz_paperentry',
            'submission',
            'privacy:metadata:submissionfiles'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user data for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The list of contexts containing data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Attempts created by the import tool (student data).
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modlevel1
               JOIN {quiz_paperentry_attempts} pa ON pa.quizid = cm.instance
              WHERE pa.userid = :uid1",
            ['modlevel1' => CONTEXT_MODULE, 'uid1' => $userid]
        );

        // Grader assignments.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modlevel2
               JOIN {quiz_paperentry_graders} pg ON pg.quizid = cm.instance
              WHERE pg.userid = :uid2",
            ['modlevel2' => CONTEXT_MODULE, 'uid2' => $userid]
        );

        // Grader CSV submissions.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modlevel3
               JOIN {quiz_paperentry_submissions} ps ON ps.quizid = cm.instance
              WHERE ps.userid = :uid3",
            ['modlevel3' => CONTEXT_MODULE, 'uid3' => $userid]
        );

        return $contextlist;
    }

    /**
     * Get the list of users within a context who have data with this plugin.
     *
     * @param userlist $userlist The userlist to populate.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $params = ['cmid' => $context->instanceid];

        $userlist->add_from_sql('userid',
            "SELECT pa.userid
               FROM {quiz_paperentry_attempts} pa
               JOIN {course_modules} cm ON cm.instance = pa.quizid
              WHERE cm.id = :cmid",
            $params
        );

        $userlist->add_from_sql('userid',
            "SELECT pg.userid
               FROM {quiz_paperentry_graders} pg
               JOIN {course_modules} cm ON cm.instance = pg.quizid
              WHERE cm.id = :cmid",
            $params
        );

        $userlist->add_from_sql('userid',
            "SELECT ps.userid
               FROM {quiz_paperentry_submissions} ps
               JOIN {course_modules} cm ON cm.instance = ps.quizid
              WHERE cm.id = :cmid",
            $params
        );
    }

    /**
     * Export all user data in the given approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('quiz', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $quizid = $cm->instance;

            // Export attempt-log records for this student.
            $attempts = $DB->get_records('quiz_paperentry_attempts',
                ['quizid' => $quizid, 'userid' => $userid]);
            if (!empty($attempts)) {
                $rows = [];
                foreach ($attempts as $row) {
                    $rows[] = [
                        'attemptid'   => $row->attemptid,
                        'timecreated' => transform::datetime($row->timecreated),
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:attemptlog', 'quiz_paperentry')],
                    (object)['attempts' => $rows]
                );
            }

            // Export grader assignment.
            $grader = $DB->get_record('quiz_paperentry_graders',
                ['quizid' => $quizid, 'userid' => $userid]);
            if ($grader) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:graders', 'quiz_paperentry')],
                    (object)[
                        'quizid'      => $grader->quizid,
                        'timecreated' => transform::datetime($grader->timecreated),
                    ]
                );
            }

            // Export grader CSV submission (metadata record + actual file).
            $submission = $DB->get_record('quiz_paperentry_submissions',
                ['quizid' => $quizid, 'userid' => $userid]);
            if ($submission) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:submissions', 'quiz_paperentry')],
                    (object)[
                        'quizid'       => $submission->quizid,
                        'timecreated'  => transform::datetime($submission->timecreated),
                        'timemodified' => transform::datetime($submission->timemodified),
                    ]
                );
                // Export the stored CSV file via the File API.
                writer::with_context($context)->export_area_files(
                    [get_string('privacy:metadata:submissions', 'quiz_paperentry')],
                    'quiz_paperentry',
                    'submission',
                    $userid
                );
            }
        }
    }

    /**
     * Delete all personal data for all users in the given context.
     *
     * @param \context $context The context to delete data in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid);
        if (!$cm) {
            return;
        }
        $quizid = $cm->instance;

        $DB->delete_records('quiz_paperentry_attempts',    ['quizid' => $quizid]);
        $DB->delete_records('quiz_paperentry_graders',     ['quizid' => $quizid]);
        $DB->delete_records('quiz_paperentry_submissions', ['quizid' => $quizid]);
        // Delete all submission files stored via the File API for this context.
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'quiz_paperentry', 'submission');
    }

    /**
     * Delete personal data for a specific user in the given approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts for this user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('quiz', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $quizid = $cm->instance;

            $DB->delete_records('quiz_paperentry_attempts',
                ['quizid' => $quizid, 'userid' => $userid]);
            $DB->delete_records('quiz_paperentry_graders',
                ['quizid' => $quizid, 'userid' => $userid]);
            $DB->delete_records('quiz_paperentry_submissions',
                ['quizid' => $quizid, 'userid' => $userid]);
            // Delete this user's submission file.
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'quiz_paperentry', 'submission', $userid);
        }
    }

    /**
     * Delete personal data for a list of users within a given context.
     *
     * @param approved_userlist $userlist The approved userlist.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid);
        if (!$cm) {
            return;
        }
        $quizid = $cm->instance;

        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED, 'uid');
        $params['qid'] = $quizid;

        $DB->delete_records_select('quiz_paperentry_attempts',
            "quizid = :qid AND userid $insql", $params);
        $DB->delete_records_select('quiz_paperentry_graders',
            "quizid = :qid AND userid $insql", $params);
        $DB->delete_records_select('quiz_paperentry_submissions',
            "quizid = :qid AND userid $insql", $params);
        // Delete submission files for each user in this context.
        $fs = get_file_storage();
        foreach ($userlist->get_userids() as $uid) {
            $fs->delete_area_files($context->id, 'quiz_paperentry', 'submission', $uid);
        }
    }
}
