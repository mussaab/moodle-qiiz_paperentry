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
 * Edit Answer panel — lets a manager change one student answer in-place.
 *
 * @module     quiz_paperentry/edit_answer
 * @copyright  2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise the Edit Answer panel.
 *
 * @param {Object}  qdata        Map of slot → {name, answers:[string,…]}.
 * @param {Object}  current      Map of userid → {slot → 0-based index | null}.
 * @param {string}  noattemptmsg Message shown when a student has no finished attempt.
 * @param {string}  curlabelmsg  Label appended to the student's current answer.
 */
export const init = (qdata, current, noattemptmsg, curlabelmsg) => {
    const studentSel  = document.getElementById('ea-student-select');
    const questionSel = document.getElementById('ea-question-select');
    const answersArea = document.getElementById('ea-answers-area');
    const submitBtn   = document.getElementById('ea-submit-btn');
    const hiddenUser  = document.getElementById('ea-userid-hidden');
    const hiddenSlot  = document.getElementById('ea-slot-hidden');
    const hiddenIndex = document.getElementById('ea-index-hidden');
    if (!studentSel) {
        return;
    }

    /**
     * Rebuild the answer options area for the currently selected student + question.
     */
    const updateAnswers = () => {
        const uid  = studentSel.value;
        const slot = questionSel.value;

        answersArea.innerHTML   = '';
        hiddenUser.value        = uid;
        hiddenSlot.value        = slot;
        hiddenIndex.value       = '';
        submitBtn.disabled      = true;

        if (!uid || !slot) {
            return;
        }

        const q = qdata[slot];
        if (!q) {
            return;
        }

        // No finished attempt for this student.
        if (!(uid in current)) {
            const p = document.createElement('p');
            p.className   = 'text-muted font-italic';
            p.textContent = noattemptmsg;
            answersArea.appendChild(p);
            return;
        }

        const userSlots  = current[uid] || {};
        const currentIdx = (slot in userSlots) ? userSlots[slot] : null;

        q.answers.forEach((text, idx) => {
            const isCurrent = (currentIdx !== null && currentIdx === idx);

            const div = document.createElement('div');
            div.className = 'form-check';

            const radio = document.createElement('input');
            radio.type      = 'radio';
            radio.name      = 'ea_answer_ui';
            radio.className = 'form-check-input';
            radio.id        = 'ea-ans-' + idx;
            radio.value     = idx;
            radio.addEventListener('change', () => {
                hiddenIndex.value  = idx;
                submitBtn.disabled = false;
            });

            const lbl = document.createElement('label');
            lbl.className = 'form-check-label';
            lbl.htmlFor   = 'ea-ans-' + idx;
            lbl.appendChild(document.createTextNode(String.fromCharCode(65 + idx) + '. ' + text));

            if (isCurrent) {
                const note = document.createElement('small');
                note.style.cssText =
                    'background:#f0f0f0;border-radius:3px;padding:1px 5px;margin-left:6px;color:#888';
                note.textContent = curlabelmsg;
                lbl.appendChild(note);
            }

            div.appendChild(radio);
            div.appendChild(lbl);
            answersArea.appendChild(div);
        });
    };

    studentSel.addEventListener('change', updateAnswers);
    questionSel.addEventListener('change', updateAnswers);
};
