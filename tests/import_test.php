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
 * PHPUnit tests for quiz_paperentry import logic.
 *
 * @package     quiz_paperentry
 * @category    test
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_paperentry;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the import class.
 *
 * @covers \quiz_paperentry\import
 */
class import_test extends \advanced_testcase {

    /** @var \stdClass Course used across tests. */
    private \stdClass $course;

    /** @var \stdClass Quiz used across tests. */
    private \stdClass $quiz;

    /** @var \stdClass Course-module record. */
    private \stdClass $cm;

    /** @var \stdClass[] Students enrolled in the course. */
    private array $students = [];

    /**
     * Set up a minimal quiz with two multichoice questions and three students.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        // Create students.
        for ($i = 1; $i <= 3; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $this->course->id, 'student');
            $this->students[] = $student;
        }

        // Create quiz.
        $this->quiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $this->cm   = get_coursemodule_from_instance('quiz', $this->quiz->id);

        // Add two multichoice questions.
        $qgen = $generator->get_plugin_generator('core_question');
        $cat  = $qgen->create_question_category();
        for ($s = 1; $s <= 2; $s++) {
            $q = $qgen->create_question('multichoice', null, [
                'category' => $cat->id,
                'name'     => 'Q' . $s,
            ]);
            quiz_add_quiz_question($q->id, $this->quiz, 0);
        }
    }

    /**
     * Helper — write a CSV string to a temp file and return its path.
     */
    private function csv_to_tempfile(string $csv): string {
        $path = tempnam(sys_get_temp_dir(), 'pe_test_');
        file_put_contents($path, $csv);
        return $path;
    }

    /**
     * A valid CSV with correct answer texts should import without errors.
     */
    public function test_valid_import_succeeds(): void {
        $student = $this->students[0];
        $importer = new import($this->quiz, $this->cm, $this->course);

        // Build header from questions.
        $questions = export::get_questions($this->quiz);
        $qnames = array_map(fn($q) => export::csv_safe($q->name), $questions);
        $header = implode(',', array_merge(['userid', 'firstname', 'lastname'], $qnames));

        // Use positional letter for answer (a = first option).
        $row = implode(',', [$student->id, $student->firstname, $student->lastname,
            ...array_fill(0, count($questions), 'a')]);

        $file = $this->csv_to_tempfile($header . "\n" . $row . "\n");
        $result = $importer->process($file);
        unlink($file);

        $this->assertTrue($result);
        $this->assertEmpty($importer->errors);
        $this->assertEquals(1, $importer->imported);
    }

    /**
     * A CSV with an unrecognised answer value must fail the entire import
     * (no attempts committed) and report an error — not just a warning.
     */
    public function test_bad_answer_aborts_entire_import(): void {
        global $DB;

        $student = $this->students[0];
        $importer = new import($this->quiz, $this->cm, $this->course);

        $questions = export::get_questions($this->quiz);
        $qnames    = array_map(fn($q) => export::csv_safe($q->name), $questions);
        $header    = implode(',', array_merge(['userid', 'firstname', 'lastname'], $qnames));

        // First answer is valid (a), second is garbage.
        $values = array_fill(0, count($questions), 'a');
        $values[count($values) - 1] = 'INVALID_OPTION_XYZ';
        $row = implode(',', [$student->id, $student->firstname, $student->lastname, ...$values]);

        $attemptsBefore = $DB->count_records('quiz_attempts', ['quiz' => $this->quiz->id]);

        $file = $this->csv_to_tempfile($header . "\n" . $row . "\n");
        $result = $importer->process($file);
        unlink($file);

        $this->assertFalse($result);
        $this->assertNotEmpty($importer->errors);
        $this->assertEquals(0, $importer->imported);

        // Crucially, no new attempts must have been written to the DB.
        $attemptsAfter = $DB->count_records('quiz_attempts', ['quiz' => $this->quiz->id]);
        $this->assertEquals($attemptsBefore, $attemptsAfter,
            'No attempts should be committed when validation fails');
    }

    /**
     * A CSV with an unknown user ID should skip that row but not abort.
     */
    public function test_unknown_userid_is_skipped_with_warning(): void {
        $student = $this->students[0];
        $importer = new import($this->quiz, $this->cm, $this->course);

        $questions = export::get_questions($this->quiz);
        $qnames    = array_map(fn($q) => export::csv_safe($q->name), $questions);
        $header    = implode(',', array_merge(['userid', 'firstname', 'lastname'], $qnames));

        // Unknown user id = 999999.
        $badrow  = implode(',', [999999, 'Unknown', 'User', ...array_fill(0, count($questions), 'a')]);
        $goodrow = implode(',', [$student->id, $student->firstname, $student->lastname,
            ...array_fill(0, count($questions), 'a')]);

        $file = $this->csv_to_tempfile($header . "\n" . $badrow . "\n" . $goodrow . "\n");
        $result = $importer->process($file);
        unlink($file);

        $this->assertTrue($result);
        $this->assertEmpty($importer->errors);
        $this->assertNotEmpty($importer->warnings);
        $this->assertEquals(1, $importer->skipped);
        $this->assertEquals(1, $importer->imported);
    }

    /**
     * A CSV with a wrong header (missing question column) must fail immediately.
     */
    public function test_wrong_header_fails(): void {
        $student  = $this->students[0];
        $importer = new import($this->quiz, $this->cm, $this->course);

        // Deliberately wrong header.
        $file = $this->csv_to_tempfile("userid,firstname,lastname,WRONG_COLUMN\n" .
            "{$student->id},{$student->firstname},{$student->lastname},a\n");
        $result = $importer->process($file);
        unlink($file);

        $this->assertFalse($result);
        $this->assertNotEmpty($importer->errors);
        $this->assertEquals(0, $importer->imported);
    }

    /**
     * Re-importing for the same student should replace the existing attempt.
     */
    public function test_reimport_replaces_previous_attempt(): void {
        global $DB;

        $student  = $this->students[0];
        $questions = export::get_questions($this->quiz);
        $qnames    = array_map(fn($q) => export::csv_safe($q->name), $questions);
        $header    = implode(',', array_merge(['userid', 'firstname', 'lastname'], $qnames));
        $row       = implode(',', [$student->id, $student->firstname, $student->lastname,
            ...array_fill(0, count($questions), 'a')]);
        $csv       = $header . "\n" . $row . "\n";

        $imp1 = new import($this->quiz, $this->cm, $this->course);
        $file = $this->csv_to_tempfile($csv);
        $imp1->process($file);
        unlink($file);

        $countAfterFirst = $DB->count_records('quiz_attempts',
            ['quiz' => $this->quiz->id, 'userid' => $student->id]);

        $imp2 = new import($this->quiz, $this->cm, $this->course);
        $file = $this->csv_to_tempfile($csv);
        $imp2->process($file);
        unlink($file);

        $countAfterSecond = $DB->count_records('quiz_attempts',
            ['quiz' => $this->quiz->id, 'userid' => $student->id]);

        $this->assertEquals($countAfterFirst, $countAfterSecond,
            'Re-importing should replace the attempt, not add a second one');
    }
}
