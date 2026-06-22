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
 * CSV comparison helper for quiz_paperentry.
 *
 * @package     quiz_paperentry
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_paperentry;

/**
 * Parses grader CSV submissions and compares answers across graders.
 */
class comparison {

    /**
     * Parse a CSV string into a structured array.
     *
     * Strips the UTF-8 BOM if present. Detects comma vs semicolon delimiter.
     * Expects columns: userid, firstname, lastname, [extra fields...], [questions...].
     * Identity columns (userid, firstname, lastname) are extracted; all remaining
     * columns are treated as question/data columns.
     *
     * @param string $csvdata  Raw CSV text.
     * @return array  [
     *   'rows'     => [userid => [colname => value]],
     *   'students' => [userid => 'Firstname Lastname'],
     *   'columns'  => [col1, col2, ...],   // all non-identity headers
     * ]
     */
    public static function parse(string $csvdata): array {
        // Strip BOM.
        if (str_starts_with($csvdata, "\xEF\xBB\xBF")) {
            $csvdata = substr($csvdata, 3);
        }

        // Detect delimiter: count commas vs semicolons in first line.
        $firstline = strtok($csvdata, "\n");
        $commas    = substr_count($firstline, ',');
        $semis     = substr_count($firstline, ';');
        $delim     = $semis > $commas ? ';' : ',';

        $lines = [];
        $handle = fopen('data://text/plain,' . rawurlencode($csvdata), 'r');
        while (($row = fgetcsv($handle, 0, $delim)) !== false) {
            $lines[] = $row;
        }
        fclose($handle);

        if (count($lines) < 2) {
            return ['rows' => [], 'students' => [], 'columns' => []];
        }

        $headers = array_map('trim', $lines[0]);
        // Find identity column positions.
        $useridcol    = array_search('userid',    $headers);
        $firstnamecol = array_search('firstname', $headers);
        $lastnamecol  = array_search('lastname',  $headers);

        if ($useridcol === false) {
            return ['rows' => [], 'students' => [], 'columns' => []];
        }

        // Non-identity columns start after index 2 (userid, firstname, lastname).
        // We include ALL columns from index 3+ as data columns.
        $datacols = [];
        for ($i = 3; $i < count($headers); $i++) {
            $datacols[] = $headers[$i];
        }

        $rows     = [];
        $students = [];

        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (count($line) < count($headers)) {
                // Pad short rows.
                $line = array_pad($line, count($headers), '');
            }
            $userid = (int)trim($line[$useridcol]);
            if (!$userid) {
                continue;
            }
            $firstname = $firstnamecol !== false ? trim($line[$firstnamecol]) : '';
            $lastname  = $lastnamecol !== false ? trim($line[$lastnamecol]) : '';
            $students[$userid] = trim("$firstname $lastname");

            $rowdata = [];
            for ($j = 3; $j < count($headers); $j++) {
                $colname = $headers[$j];
                $rowdata[$colname] = isset($line[$j]) ? trim($line[$j]) : '';
            }
            $rows[$userid] = $rowdata;
        }

        return [
            'rows'     => $rows,
            'students' => $students,
            'columns'  => $datacols,
        ];
    }

    /**
     * Compare answers across multiple grader CSV submissions.
     *
     * Only compares columns that correspond to question names provided in
     * $questionnames — extra profile field columns are ignored.
     *
     * @param array    $submissions    [grader_userid => csvdata_string]
     * @param array    $questionnames  Question name strings to compare.
     * @param array    $gradernames    [grader_userid => \stdClass with firstname/lastname]
     * @return array [
     *   'mismatches'  => [ ['userid', 'student', 'question', 'answers' => [gid => answer]], ... ],
     *   'all_match'   => bool,
     *   'grader_ids'  => int[],
     *   'gradernames' => array,
     *   'students'    => [userid => 'name'],
     * ]
     */
    public static function compare(array $submissions, array $questionnames, array $gradernames = []): array {
        if (count($submissions) < 2) {
            return [
                'mismatches'  => [],
                'all_match'   => true,
                'grader_ids'  => array_keys($submissions),
                'gradernames' => $gradernames,
                'students'    => [],
            ];
        }

        // Parse all submissions.
        $parsed = [];
        $allstudents = [];
        foreach ($submissions as $graderid => $csvdata) {
            $p = self::parse($csvdata);
            $parsed[$graderid] = $p;
            foreach ($p['students'] as $uid => $name) {
                $allstudents[$uid] = $name;
            }
        }

        $graderids = array_keys($submissions);
        $qnames    = array_values($questionnames);
        $mismatches = [];

        foreach ($allstudents as $studentid => $studentname) {
            foreach ($qnames as $qname) {
                $answers = [];
                foreach ($graderids as $gid) {
                    $answers[$gid] = $parsed[$gid]['rows'][$studentid][$qname] ?? '';
                }
                $unique = array_unique($answers);
                if (count($unique) > 1) {
                    $mismatches[] = [
                        'userid'   => $studentid,
                        'student'  => $studentname,
                        'question' => $qname,
                        'answers'  => $answers,
                    ];
                }
            }
        }

        return [
            'mismatches'  => $mismatches,
            'all_match'   => empty($mismatches),
            'grader_ids'  => $graderids,
            'gradernames' => $gradernames,
            'students'    => $allstudents,
        ];
    }
}
