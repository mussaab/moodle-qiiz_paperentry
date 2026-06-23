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
 * CSV import and attempt-creation helper for quiz_paperentry.
 *
 * @package     quiz_paperentry
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_paperentry;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Reads a filled answer-sheet CSV and creates graded quiz attempts.
 *
 * Expected CSV format (produced by export::send_csv):
 *   userid, firstname, lastname, Q1_name, Q2_name, ...
 *   12345,  Ahmed,     Ali,      A,        C,       ...
 *
 * Answer values are the answer option texts exactly as they appear in the
 * question (case-insensitive, HTML stripped). Blank cells are treated as
 * unanswered. An unrecognised value produces a per-row warning and skips
 * that student.
 *
 * Only attempts previously created by this plugin (tracked in
 * quiz_paperentry_attempts) are replaced on re-import. Manually-created or
 * manually-graded attempts are never touched.
 */
class import {

    /** @var \stdClass */
    private \stdClass $quiz;

    /** @var mixed  cm_info or stdClass — no type hint to accept both. */
    private $cm;

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass[] Questions indexed by name string. */
    private array $questions = [];

    /** @var \stdClass[] Enrolled students indexed by userid. */
    private array $students = [];

    /** @var array<string,int> Column header → array index map. */
    private array $colmap = [];

    /**
     * Optional value substitution map set by the manager via the UI.
     * Keys are raw cell values (lowercased); values are the replacement strings
     * that are then passed through the normal letter/number/text matching.
     *
     * @var array<string,string>
     */
    private array $remapping = [];

    /** @var string[] Error messages (fatal — abort entire import). */
    public array $errors = [];

    /** @var string[] Warning messages per row. */
    public array $warnings = [];

    /** @var int Count of successfully imported attempts. */
    public int $imported = 0;

    /** @var int Count of skipped rows. */
    public int $skipped = 0;

    /**
     * Apply a substitution map before answer matching.
     *
     * The manager builds this map via the import UI when graders used values
     * that differ from the standard letter/number/text conventions. Each key
     * is a raw cell value (case-insensitive); the corresponding value replaces
     * it before normal matching runs.
     *
     * Example: ['a' => '1', 'b' => '2'] converts letter entries to numbers.
     *
     * @param array<string,string> $map Associative array of from => to pairs.
     * @return void
     */
    public function set_remapping(array $map): void {
        $this->remapping = [];
        foreach ($map as $from => $to) {
            $from = trim((string)$from);
            $to   = trim((string)$to);
            if ($from !== '') {
                $this->remapping[strtolower($from)] = $to;
            }
        }
    }

    /**
     * Initialise the importer with the target quiz context.
     *
     * @param \stdClass $quiz   Quiz record from {quiz}.
     * @param mixed     $cm     Course-module record (cm_info or stdClass).
     * @param \stdClass $course Course record.
     */
    public function __construct(\stdClass $quiz, $cm, \stdClass $course) {
        $this->quiz   = $quiz;
        $this->cm     = $cm;
        $this->course = $course;
    }

    /**
     * Process an uploaded CSV file.
     *
     * Uses a two-pass approach: all rows are validated first, and attempts are
     * only written to the database when every row passes validation. If any row
     * contains an unrecognised answer the entire import is aborted with errors
     * so no partial data ends up in the gradebook.
     *
     * @param string $filepath  Path to the temporary uploaded file.
     * @return bool  True if all rows were valid and attempts were committed.
     */
    public function process(string $filepath): bool {
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            $this->errors[] = get_string('importerror_parse', 'quiz_paperentry');
            return false;
        }

        // Load reference data.
        $this->ensure_questions_loaded();
        $this->students = export::get_enrolled_students($this->cm, $this->course);

        // Auto-detect delimiter by comparing tab vs comma counts on the first line.
        $firstline = fgets($handle);
        rewind($handle);
        $delimiter = (substr_count($firstline, "\t") > substr_count($firstline, ',')) ? "\t" : ',';

        // Read and validate header row.
        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            fclose($handle);
            $this->errors[] = get_string('importerror_parse', 'quiz_paperentry');
            return false;
        }
        $header    = array_map('trim', $header);
        $header[0] = ltrim($header[0], "\xEF\xBB\xBF"); // Strip UTF-8 BOM added by Excel.

        if (!$this->validate_header($header)) {
            fclose($handle);
            return false;
        }

        foreach ($header as $i => $col) {
            $this->colmap[$col] = $i;
        }

        // Pass 1: validate every row, collect pending attempts.
        $pending = []; // Each entry: ['userid' => int, 'answers' => array].
        $rownum  = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rownum++;
            $result = $this->validate_row($row, $rownum);
            if ($result === null) {
                continue; // Blank or skipped row — already counted/warned.
            }
            $pending[] = $result;
        }
        fclose($handle);

        // Abort if any validation error was recorded.
        if (!empty($this->errors)) {
            return false;
        }

        // Pass 2: commit all validated attempts.
        foreach ($pending as $item) {
            try {
                $this->create_attempt($item['userid'], $item['answers']);
                $this->imported++;
            } catch (\Throwable $e) {
                $this->errors[] = get_string('importerror_attempt', 'quiz_paperentry', (object)[
                    'row'    => $item['rownum'],
                    'userid' => $item['userid'],
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return empty($this->errors);
    }

    // Private helpers.

    /**
     * Validate that the CSV header contains the required identity columns and
     * all quiz question columns (extra profile-field columns are ignored).
     *
     * @param string[] $header Trimmed column names from the first CSV row.
     * @return bool True if the header is valid; false and an error message added otherwise.
     */
    private function validate_header(array $header): bool {
        if (($header[0] ?? '') !== 'userid' ||
            ($header[1] ?? '') !== 'firstname' ||
            ($header[2] ?? '') !== 'lastname') {
            $this->errors[] = get_string('importerror_header', 'quiz_paperentry');
            return false;
        }

        // Collect only columns that match a known question name (skips extra profile fields).
        $csvquestions = array_values(array_filter(
            array_slice($header, 3),
            fn($col) => isset($this->questions[$col])
        ));

        $quizquestions = array_keys($this->questions);

        if ($csvquestions !== $quizquestions) {
            $this->errors[] = get_string('importerror_header', 'quiz_paperentry');
            return false;
        }

        return true;
    }

    /**
     * Validate a single CSV data row during pass 1.
     *
     * Returns a pending-attempt array on success, or null if the row should be
     * silently skipped or has already been counted as skipped/errored.
     *
     * Bad answer values are promoted to $this->errors (blocking the entire
     * import) rather than per-row warnings, so no partial data is committed.
     *
     * @param string[] $row    Raw values from fgetcsv() for the current row.
     * @param int      $rownum 1-based row number used in messages.
     * @return array{userid:int,rownum:int,answers:array}|null
     */
    private function validate_row(array $row, int $rownum): ?array {
        $userid    = trim($row[$this->colmap['userid']] ?? '');
        $firstname = trim($row[$this->colmap['firstname']] ?? '');
        $lastname  = trim($row[$this->colmap['lastname']] ?? '');

        if ($userid === '') {
            return null; // Blank row, skip silently.
        }

        if (!isset($this->students[(int)$userid])) {
            $this->warnings[] = get_string('importerror_nouser', 'quiz_paperentry', $rownum);
            $this->skipped++;
            return null;
        }

        $answers = [];
        foreach ($this->questions as $name => $q) {
            $col      = $this->colmap[$name];
            $rawvalue = trim($row[$col] ?? '');

            if ($rawvalue === '') {
                $answers[$name] = null; // Unanswered.
                continue;
            }

            // Apply manager-defined substitution before matching.
            $lookupvalue = $this->remapping[strtolower($rawvalue)] ?? $rawvalue;

            // Single letter (a/A … z/Z) → 0-based positional index (a=0, b=1 …).
            // Single digit (1 … 9) → 0-based positional index (1=0, 2=1 …).
            // Any other value → match against answer option text (case-insensitive, HTML stripped).
            $matchedindex = null;
            if (strlen($lookupvalue) === 1 && ctype_alpha($lookupvalue)) {
                $idx = ord(strtolower($lookupvalue)) - ord('a');
                if (isset($q->answers[$idx])) {
                    $matchedindex = $idx;
                }
            } else if (strlen($lookupvalue) === 1 && ctype_digit($lookupvalue) && $lookupvalue !== '0') {
                $idx = (int)$lookupvalue - 1;
                if (isset($q->answers[$idx])) {
                    $matchedindex = $idx;
                }
            } else {
                foreach ($q->answers as $j => $ans) {
                    $anstext = trim(html_entity_decode(
                        strip_tags($ans->answer), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if (strcasecmp($anstext, $lookupvalue) === 0) {
                        $matchedindex = $j;
                        break;
                    }
                }
            }

            if ($matchedindex === null) {
                // Promote to error — aborts the entire import so no partial data
                // is written and all problems are surfaced at once.
                $validoptions = implode(', ', array_map(function($ans) {
                    return '"' . trim(html_entity_decode(strip_tags($ans->answer), ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '"';
                }, $q->answers));
                $this->errors[] = get_string('importerror_badanswer', 'quiz_paperentry', (object)[
                    'row'      => $rownum,
                    'answer'   => $rawvalue,
                    'question' => $name,
                    'options'  => $validoptions,
                ]);
                return null;
            }

            $answers[$name] = $matchedindex; // Store 0-based index directly.
        }

        if (empty(array_filter($answers, fn($v) => $v !== null))) {
            $this->warnings[] = "Row {$rownum} ({$firstname} {$lastname}): " .
                get_string('skipreason_noanswers', 'quiz_paperentry');
            $this->skipped++;
            return null;
        }

        return ['userid' => (int)$userid, 'rownum' => $rownum, 'answers' => $answers];
    }

    /**
     * Populate $this->questions from the quiz if not already loaded.
     *
     * Questions are indexed by their plain name (matching CSV column headers).
     * Duplicate question names have the slot number appended to ensure uniqueness.
     *
     * @return void
     */
    private function ensure_questions_loaded(): void {
        if (!empty($this->questions)) {
            return;
        }
        $seen = [];
        foreach (export::get_questions($this->quiz) as $q) {
            $key = $q->name;
            if (isset($seen[$key])) {
                // Disambiguate duplicate names by appending the slot number.
                $key = $q->name . ' (' . $q->slot . ')';
            }
            $seen[$q->name] = true;
            $this->questions[$key] = $q;
        }
    }

    /**
     * Override a single answer in a student's most recent finished attempt,
     * preserving all other answers exactly as they are.
     *
     * The existing attempt (whether plugin-created or manual) is deleted and
     * replaced; the new attempt is tracked in quiz_paperentry_attempts so
     * future imports treat it as plugin-owned.
     *
     * @param int $userid    The student whose attempt to update.
     * @param int $slot      Quiz slot number of the question to change.
     * @param int $newindex  0-based index into the question's answers array.
     * @return void
     * @throws \Throwable If the attempt cannot be loaded or re-created.
     */
    public function override_answer(int $userid, int $slot, int $newindex): void {
        global $DB;

        $this->ensure_questions_loaded();

        // Find the student's most recent finished attempt.
        $existing = $DB->get_records_sql(
            "SELECT * FROM {quiz_attempts}
              WHERE quiz = :qid AND userid = :uid AND state = 'finished'
              ORDER BY id DESC",
            ['qid' => $this->quiz->id, 'uid' => $userid],
            0, 1
        );
        $existing = reset($existing) ?: null;

        // Reconstruct a full answer map, preserving the current attempt's responses.
        $answers = [];
        if ($existing) {
            $quba = \question_engine::load_questions_usage_by_activity($existing->uniqueid);
            foreach ($this->questions as $name => $q) {
                $qa  = $quba->get_question_attempt((int)$q->slot);
                $raw = $qa->get_last_qt_data()['answer'] ?? null;
                if ($raw === null) {
                    $answers[$name] = null;
                } else if ($q->qtype === 'truefalse') {
                    // Stored value is the answer DB id; convert to 0-based index.
                    $answers[$name] = null;
                    foreach ($q->answers as $i => $ans) {
                        if ((string)$ans->id === (string)$raw) {
                            $answers[$name] = $i;
                            break;
                        }
                    }
                } else {
                    // Multichoice: stored value is already the 0-based order index.
                    $answers[$name] = (int)$raw;
                }
            }
        }

        // Apply the override for the target slot.
        foreach ($this->questions as $name => $q) {
            if ((int)$q->slot === $slot) {
                $answers[$name] = $newindex;
                break;
            }
        }

        // Delete the existing attempt (any, not just plugin-owned) then re-create.
        if ($existing) {
            quiz_delete_attempt($existing, $this->quiz);
            $DB->delete_records('quiz_paperentry_attempts', ['attemptid' => $existing->id]);
        }
        $this->create_attempt($userid, $answers);
    }

    /**
     * Replace any existing plugin-created attempt for this user and create a
     * new graded one. Manually-created attempts are never touched.
     *
     * @param int                  $userid  The student's user ID.
     * @param array<string,int|null> $answers Question name → 0-based answer index, or null if unanswered.
     * @return void
     * @throws \Throwable If attempt creation or grading fails.
     */
    private function create_attempt(int $userid, array $answers): void {
        global $DB;

        $timenow = time();

        // Only delete attempts that this plugin previously created, leaving
        // any manually-created or manually-graded attempts intact.
        $tracked = $DB->get_records('quiz_paperentry_attempts', [
            'quizid' => $this->quiz->id,
            'userid' => $userid,
        ]);
        foreach ($tracked as $row) {
            $old = $DB->get_record('quiz_attempts', ['id' => $row->attemptid]);
            if ($old) {
                quiz_delete_attempt($old, $this->quiz);
            }
            $DB->delete_records('quiz_paperentry_attempts', ['id' => $row->id]);
        }

        // Build and start a new attempt.
        $quizobj    = \mod_quiz\quiz_settings::create($this->quiz->id, $userid);
        $attempt    = quiz_prepare_and_start_new_attempt($quizobj, 1, null, false, [], [], $userid);
        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);

        // Build integer-keyed simulated responses: [slot => ['answer' => index]].
        $simulatedresponses = [];
        foreach ($this->questions as $name => $q) {
            $index = $answers[$name] ?? null;
            if ($index === null) {
                continue;
            }
            // Truefalse uses the base prepare_simulated_post_data and expects the answer DB id directly.
            // Multichoice overrides prepare_simulated_post_data and matches by plain-text answer value.
            if ($q->qtype === 'truefalse') {
                $simulatedresponses[(int)$q->slot] = ['answer' => $q->answers[$index]->id];
            } else {
                $answertext = trim(html_entity_decode(
                    strip_tags($q->answers[$index]->answer), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $simulatedresponses[(int)$q->slot] = ['answer' => $answertext];
            }
        }

        if (!empty($simulatedresponses)) {
            $attemptobj->process_submitted_actions($timenow, false, $simulatedresponses);
        }

        $attemptobj->process_finish($timenow, false);

        // Record this attempt so future imports only replace our own work.
        $DB->insert_record('quiz_paperentry_attempts', (object)[
            'attemptid'   => $attempt->id,
            'quizid'      => $this->quiz->id,
            'userid'      => $userid,
            'timecreated' => $timenow,
        ]);
    }
}
