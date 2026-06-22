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
 * Value-substitution mapping widget for the Paper Entry import form.
 *
 * When inject=false the widget is inside the target form — inputs are named
 * directly and submitted with the form.
 *
 * When inject=true the widget sits outside the form — on submit the JS
 * appends hidden copies of each pair into the nearest sibling form found
 * inside the same .card-body ancestor.
 *
 * @module     quiz_paperentry/mapping_widget
 * @copyright  2026 Mossaab Mohamed Ali <mosab@mussaab.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Shared setup for a mapping widget. Returns {addRow} for external use.
 *
 * @param {string}  widgetid   HTML id of the widget wrapper element.
 * @param {string}  fromlabel  Placeholder text for the "grader wrote" input.
 * @param {string}  tolabel    Placeholder text for the "treat as" input.
 * @param {boolean} inject     True when inputs must be injected into a sibling form on submit.
 * @returns {{addRow: Function}|null}
 */
const setup = (widgetid, fromlabel, tolabel, inject) => {
    const widget = document.getElementById(widgetid);
    if (!widget) {
        return null;
    }
    const rows   = widget.querySelector('.pe-map-rows');
    const addBtn = widget.querySelector('.pe-map-add');

    /**
     * Add one substitution row to the widget.
     *
     * @param {string} from Initial "grader wrote" value.
     * @param {string} to   Initial "treat as" value.
     */
    const addRow = (from, to) => {
        const row = document.createElement('div');
        row.className = 'pe-map-row d-flex align-items-center mb-1';
        row.style.gap = '6px';

        const fi = document.createElement('input');
        fi.type         = 'text';
        fi.name         = inject ? '' : 'remap_from[]';
        fi.placeholder  = fromlabel;
        fi.value        = from || '';
        fi.className    = 'form-control form-control-sm';
        fi.style.width  = '110px';
        fi.dataset.role = 'from';

        const arrow = document.createElement('span');
        arrow.textContent = '→';

        const ti = document.createElement('input');
        ti.type         = 'text';
        ti.name         = inject ? '' : 'remap_to[]';
        ti.placeholder  = tolabel;
        ti.value        = to || '';
        ti.className    = 'form-control form-control-sm';
        ti.style.width  = '110px';
        ti.dataset.role = 'to';

        const del = document.createElement('button');
        del.type        = 'button';
        del.textContent = '×';
        del.className   = 'btn btn-sm btn-outline-danger';
        del.addEventListener('click', () => row.remove());

        row.appendChild(fi);
        row.appendChild(arrow);
        row.appendChild(ti);
        row.appendChild(del);
        rows.appendChild(row);
    };

    addBtn.addEventListener('click', () => addRow('', ''));

    if (inject) {
        // Attach to the nearest form inside the same card-body.
        const cardBody = widget.closest('.card-body');
        const form     = cardBody ? cardBody.querySelector('form') : null;
        if (form) {
            form.addEventListener('submit', () => {
                rows.querySelectorAll('.pe-map-row').forEach(r => {
                    const from = r.querySelector('[data-role=from]').value.trim();
                    const to   = r.querySelector('[data-role=to]').value.trim();
                    if (!from) {
                        return;
                    }
                    const hf  = document.createElement('input');
                    hf.type   = 'hidden';
                    hf.name   = 'remap_from[]';
                    hf.value  = from;
                    form.appendChild(hf);
                    const ht  = document.createElement('input');
                    ht.type   = 'hidden';
                    ht.name   = 'remap_to[]';
                    ht.value  = to;
                    form.appendChild(ht);
                });
            });
        }
    }

    return {addRow};
};

/**
 * Initialise a mapping widget instance.
 *
 * @param {string}  widgetid   HTML id of the widget wrapper element.
 * @param {string}  fromlabel  Placeholder text for the "grader wrote" input.
 * @param {string}  tolabel    Placeholder text for the "treat as" input.
 * @param {boolean} inject     True when inputs must be injected into a sibling form on submit.
 */
export const init = (widgetid, fromlabel, tolabel, inject) => {
    setup(widgetid, fromlabel, tolabel, inject);
};

/**
 * Initialise a mapping widget pre-populated with saved substitution pairs.
 *
 * @param {string}  widgetid    HTML id of the widget wrapper element.
 * @param {string}  fromlabel   Placeholder text for the "grader wrote" input.
 * @param {string}  tolabel     Placeholder text for the "treat as" input.
 * @param {boolean} inject      True when inputs must be injected into a sibling form on submit.
 * @param {Object}  savedpairs  Plain object of {from: to} pairs saved in the DB.
 */
export const initWithSaved = (widgetid, fromlabel, tolabel, inject, savedpairs) => {
    const instance = setup(widgetid, fromlabel, tolabel, inject);
    if (instance && savedpairs && typeof savedpairs === 'object') {
        Object.entries(savedpairs).forEach(([from, to]) => instance.addRow(from, to));
    }
};
