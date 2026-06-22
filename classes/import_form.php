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
 * Import form for quiz_paperentry.
 *
 * @package     quiz_paperentry
 * @copyright   2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_paperentry;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Moodle form for uploading a filled answer-sheet CSV.
 *
 * Uses the standard filepicker element so files go through Moodle's draft file
 * area rather than raw $_FILES — avoids PHP 8.3 incompatibilities in the legacy
 * PEAR HTML_QuickForm_file class and provides consistent UX with the rest of
 * Moodle's upload UI.
 *
 * Extension (.csv) and size (MAX_BYTES) checks are enforced server-side in
 * quiz_paperentry_report::handle_import() after the form is submitted, because
 * filepicker does not expose uploaded file metadata in validation().
 */
class import_form extends \moodleform {

    /** Maximum accepted file size (5 MB). */
    const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Define the form elements: a filepicker for the CSV and a submit button.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'csvfile',
            get_string('importfile', 'quiz_paperentry'), null, [
                'maxbytes'       => self::MAX_BYTES,
                'accepted_types' => ['.csv'],
            ]
        );
        $mform->addRule('csvfile', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(false, get_string('importsubmit', 'quiz_paperentry'));
    }
}
