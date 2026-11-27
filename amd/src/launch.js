// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Submits the LTI launch form and toggles its debug data.
 *
 * @module     local_intellistream/launch
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    return {
        /**
         * Find the launch form; show or hide the debug data on request, and submit
         * the form unless it is in debug mode.
         */
        init: function() {
            var form = document.querySelector('[data-region="local-intellistream-ltilaunch"]');
            if (!form) {
                return;
            }

            var toggle = form.querySelector('[data-action="local-intellistream-ltidebug"]');
            if (toggle) {
                toggle.addEventListener('click', function() {
                    var panel = document.getElementById(toggle.getAttribute('aria-controls'));
                    if (!panel) {
                        return;
                    }
                    var hidden = panel.classList.toggle('d-none');
                    toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
                });
            }

            // The submit button stays visible, so the user can still open the
            // dashboard if the browser does not leave the page.
            if (form.getAttribute('data-autosubmit') === '1') {
                form.submit();
            }
        }
    };
});
