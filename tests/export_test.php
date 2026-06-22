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
 * PHPUnit tests for quiz_paperentry export logic.
 *
 * @package     quiz_paperentry
 * @category    test
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_paperentry;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the export class.
 *
 * @covers \quiz_paperentry\export
 */
class export_test extends \advanced_testcase {

    /**
     * csv_safe() must prefix formula-trigger characters.
     *
     * @dataProvider csv_injection_provider
     */
    public function test_csv_safe_prevents_injection(string $input, string $expected): void {
        $this->assertEquals($expected, export::csv_safe($input));
    }

    /**
     * Data provider for CSV injection test.
     *
     * @return array[]
     */
    public static function csv_injection_provider(): array {
        return [
            'equals sign'       => ['=SUM(A1:A10)', "'=SUM(A1:A10)"],
            'plus sign'         => ['+cmd|...',     "'+cmd|..."],
            'minus sign'        => ['-2+3',         "'-2+3"],
            'at sign'           => ['@foo',          "'@foo"],
            'pipe sign'         => ['|foo',          "'|foo"],
            'percent sign'      => ['%foo',          "'%foo"],
            'safe value'        => ['True',          'True'],
            'safe number'       => ['42',            '42'],
            'empty string'      => ['',              ''],
        ];
    }

    /**
     * get_questions() returns only supported question types.
     */
    public function test_get_questions_returns_supported_types(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course    = $generator->create_course();
        $quiz      = $generator->create_module('quiz', ['course' => $course->id]);

        $qgen = $generator->get_plugin_generator('core_question');
        $cat  = $qgen->create_question_category();

        $mc = $qgen->create_question('multichoice', null, ['category' => $cat->id, 'name' => 'MC']);
        $tf = $qgen->create_question('truefalse',   null, ['category' => $cat->id, 'name' => 'TF']);
        $es = $qgen->create_question('essay',       null, ['category' => $cat->id, 'name' => 'ES']);

        quiz_add_quiz_question($mc->id, $quiz, 0);
        quiz_add_quiz_question($tf->id, $quiz, 0);
        quiz_add_quiz_question($es->id, $quiz, 0);

        $questions = export::get_questions($quiz);

        $qtypes = array_column($questions, 'qtype');
        $this->assertContains('multichoice', $qtypes, 'multichoice must be included');
        $this->assertContains('truefalse',   $qtypes, 'truefalse must be included');
        $this->assertNotContains('essay',    $qtypes, 'essay must be excluded');
    }

    /**
     * get_enrolled_students() must exclude teachers and non-editing teachers.
     */
    public function test_get_enrolled_students_excludes_teachers(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course    = $generator->create_course();
        $quiz      = $generator->create_module('quiz', ['course' => $course->id]);
        $cm        = get_coursemodule_from_instance('quiz', $quiz->id);

        $student = $generator->create_user();
        $teacher = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $students = export::get_enrolled_students($cm, $course);

        $this->assertArrayHasKey($student->id, $students,   'Student must be included');
        $this->assertArrayNotHasKey($teacher->id, $students, 'Teacher must be excluded');
    }

    /**
     * Duplicate question names must get slot-number suffixes so headers are unique.
     */
    public function test_duplicate_question_names_get_slot_suffix(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course    = $generator->create_course();
        $quiz      = $generator->create_module('quiz', ['course' => $course->id]);

        $qgen = $generator->get_plugin_generator('core_question');
        $cat  = $qgen->create_question_category();

        // Two questions with the same name.
        $q1 = $qgen->create_question('multichoice', null, ['category' => $cat->id, 'name' => 'Duplicate']);
        $q2 = $qgen->create_question('multichoice', null, ['category' => $cat->id, 'name' => 'Duplicate']);
        quiz_add_quiz_question($q1->id, $quiz, 0);
        quiz_add_quiz_question($q2->id, $quiz, 0);

        $questions = export::get_questions($quiz);

        // Build the header the same way export does.
        $seennames = [];
        $headers   = [];
        foreach ($questions as $q) {
            $colname = $q->name;
            if (isset($seennames[$colname])) {
                $colname = $q->name . ' (' . $q->slot . ')';
            }
            $seennames[$q->name] = true;
            $headers[] = export::csv_safe($colname);
        }

        $this->assertCount(2, array_unique($headers),
            'Duplicate question names must produce unique column headers');
    }
}
