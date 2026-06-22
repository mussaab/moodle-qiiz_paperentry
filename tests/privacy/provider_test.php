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
 * PHPUnit tests for the quiz_paperentry privacy provider.
 *
 * @package     quiz_paperentry
 * @category    test
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_paperentry\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for \quiz_paperentry\privacy\provider.
 *
 * @covers \quiz_paperentry\privacy\provider
 */
class provider_test extends provider_testcase {

    /** @var \stdClass Course shared across tests. */
    private \stdClass $course;

    /** @var \stdClass Quiz shared across tests. */
    private \stdClass $quiz;

    /** @var \stdClass Course-module record. */
    private \stdClass $cm;

    /** @var \context_module Module context. */
    private \context_module $context;

    /** @var \stdClass A grader user. */
    private \stdClass $grader;

    /** @var \stdClass A student user. */
    private \stdClass $student;

    /**
     * Create course, quiz, grader, student and seed the plugin tables.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $gen          = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->quiz   = $gen->create_module('quiz', ['course' => $this->course->id]);
        $this->cm     = get_coursemodule_from_instance('quiz', $this->quiz->id);
        $this->context = \context_module::instance($this->cm->id);

        $this->grader  = $gen->create_user();
        $this->student = $gen->create_user();
        $gen->enrol_user($this->grader->id,  $this->course->id, 'editingteacher');
        $gen->enrol_user($this->student->id, $this->course->id, 'student');

        $this->seed_plugin_data();
    }

    /**
     * Insert minimal rows in all four plugin tables for both test users.
     */
    private function seed_plugin_data(): void {
        global $DB;

        $now = time();

        // Grader table.
        $DB->insert_record('quiz_paperentry_graders', (object)[
            'quizid'      => $this->quiz->id,
            'userid'      => $this->grader->id,
            'timecreated' => $now,
        ]);

        // Submission table.
        $DB->insert_record('quiz_paperentry_submissions', (object)[
            'quizid'       => $this->quiz->id,
            'userid'       => $this->grader->id,
            'csvdata'      => 'userid,q1',
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        // Attempt tracking table (for the student).
        $DB->insert_record('quiz_paperentry_attempts', (object)[
            'attemptid'   => 1, // Synthetic — unit test only.
            'quizid'      => $this->quiz->id,
            'userid'      => $this->student->id,
            'timecreated' => $now,
        ]);
    }

    // -----------------------------------------------------------------------
    // get_contexts_for_userid
    // -----------------------------------------------------------------------

    /**
     * get_contexts_for_userid() must return the module context for a grader.
     */
    public function test_get_contexts_for_userid_grader(): void {
        $contextlist = provider::get_contexts_for_userid($this->grader->id);
        $this->assertContainsEquals($this->context->id,
            $contextlist->get_contextids(),
            'Module context must be returned for a grader');
    }

    /**
     * get_contexts_for_userid() must return the module context for a student
     * whose attempt was created by this plugin.
     */
    public function test_get_contexts_for_userid_student(): void {
        $contextlist = provider::get_contexts_for_userid($this->student->id);
        $this->assertContainsEquals($this->context->id,
            $contextlist->get_contextids(),
            'Module context must be returned for a student with a tracked attempt');
    }

    // -----------------------------------------------------------------------
    // get_users_in_context
    // -----------------------------------------------------------------------

    /**
     * get_users_in_context() must list both the grader and the student.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, 'quiz_paperentry');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertContains($this->grader->id,  $userids, 'Grader must appear in userlist');
        $this->assertContains($this->student->id, $userids, 'Student must appear in userlist');
    }

    // -----------------------------------------------------------------------
    // delete_data_for_all_users_in_context
    // -----------------------------------------------------------------------

    /**
     * delete_data_for_all_users_in_context() must wipe all plugin rows for that context.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertEquals(0,
            $DB->count_records('quiz_paperentry_graders', ['quizid' => $this->quiz->id]));
        $this->assertEquals(0,
            $DB->count_records('quiz_paperentry_submissions', ['quizid' => $this->quiz->id]));
        $this->assertEquals(0,
            $DB->count_records('quiz_paperentry_attempts', ['quizid' => $this->quiz->id]));
    }

    // -----------------------------------------------------------------------
    // delete_data_for_user
    // -----------------------------------------------------------------------

    /**
     * delete_data_for_user() must remove only the target user's data.
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $approvedlist = new approved_contextlist($this->grader, 'quiz_paperentry',
            [$this->context->id]);
        provider::delete_data_for_user($approvedlist);

        $this->assertEquals(0,
            $DB->count_records('quiz_paperentry_graders',
                ['quizid' => $this->quiz->id, 'userid' => $this->grader->id]),
            'Grader row must be deleted');
        $this->assertEquals(0,
            $DB->count_records('quiz_paperentry_submissions',
                ['quizid' => $this->quiz->id, 'userid' => $this->grader->id]),
            'Submission row must be deleted');

        // Student data must be untouched.
        $this->assertEquals(1,
            $DB->count_records('quiz_paperentry_attempts',
                ['quizid' => $this->quiz->id, 'userid' => $this->student->id]),
            'Other user data must not be affected');
    }

    // -----------------------------------------------------------------------
    // delete_data_for_users
    // -----------------------------------------------------------------------

    /**
     * delete_data_for_users() (bulk) must remove exactly the listed users.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $userlist = new approved_userlist($this->context, 'quiz_paperentry',
            [$this->grader->id]);
        provider::delete_data_for_users($userlist);

        $this->assertEquals(0,
            $DB->count_records('quiz_paperentry_graders',
                ['quizid' => $this->quiz->id, 'userid' => $this->grader->id]));

        // Student untouched.
        $this->assertEquals(1,
            $DB->count_records('quiz_paperentry_attempts',
                ['quizid' => $this->quiz->id, 'userid' => $this->student->id]));
    }
}
