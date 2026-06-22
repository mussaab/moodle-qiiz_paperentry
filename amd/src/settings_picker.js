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
 * Tag-picker for the Export Settings profile-field selector.
 *
 * @module     quiz_paperentry/settings_picker
 * @copyright  2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise the tag-picker.
 *
 * @param {string[]} savedfields     Field keys already saved for this quiz.
 * @param {Object}   availablelabels Map of field key → human-readable label.
 */
export const init = (savedfields, availablelabels) => {
    const picker    = document.getElementById('settings-field-picker');
    const tagArea   = document.getElementById('settings-field-tags');
    const hiddenDiv = document.getElementById('settings-hidden-inputs');
    if (!picker) {
        return;
    }

    /**
     * Render a removable tag for the given field key.
     *
     * @param {string}  key            Field identifier.
     * @param {string}  label          Human label shown on the tag.
     * @param {boolean} removefromlist Remove the matching <option> from the picker.
     */
    const addTag = (key, label, removefromlist) => {
        const tag = document.createElement('span');
        tag.className = 'badge badge-secondary d-inline-flex align-items-center';
        tag.style.cssText = 'font-size:.85rem;padding:.35em .6em;gap:4px';
        tag.dataset.key = key;

        const text = document.createTextNode(label + ' ');
        const del  = document.createElement('button');
        del.type      = 'button';
        del.innerHTML = '&times;';
        del.title     = 'Remove';
        del.style.cssText =
            'background:none;border:none;color:inherit;padding:0;' +
            'line-height:1;font-size:1rem;cursor:pointer;margin-left:2px';

        del.addEventListener('click', () => {
            const opt   = document.createElement('option');
            opt.value   = key;
            opt.text    = label;
            picker.appendChild(opt);
            const inp = hiddenDiv.querySelector('input[value="' + key + '"]');
            if (inp) {
                inp.remove();
            }
            tag.remove();
        });

        tag.appendChild(text);
        tag.appendChild(del);
        tagArea.appendChild(tag);

        const hidden  = document.createElement('input');
        hidden.type   = 'hidden';
        hidden.name   = 'extrafields[]';
        hidden.value  = key;
        hiddenDiv.appendChild(hidden);

        if (removefromlist) {
            for (let i = 0; i < picker.options.length; i++) {
                if (picker.options[i].value === key) {
                    picker.remove(i);
                    break;
                }
            }
        }
    };

    // Pre-populate from saved settings.
    savedfields.forEach(key => addTag(key, availablelabels[key] || key, false));

    picker.addEventListener('change', function() {
        const key   = this.value;
        const label = this.options[this.selectedIndex].text;
        if (!key) {
            return;
        }
        this.remove(this.selectedIndex);
        this.value = '';
        addTag(key, label, false);
    });
};
