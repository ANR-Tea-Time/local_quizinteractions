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
 * Hook callback for injecting the quiz interaction logger on quiz attempt pages.
 *
 * @package     local_quizinteractions
 * @copyright   2026 Mohamed Hamza Falih <Mohamed_Hamza.Falih@univ-lemans.fr>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizinteractions\hook;

/**
 * Hook callback class for output-related hooks.
 */
class output_hook_callback {

    /**
     * Hook callback for before_footer_html_generation.
     * Injects the AMD module call to initialize the quiz interaction logger.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $CFG;

        // The finish form is rendered on the quiz summary page in Moodle 4.3.
        if (!in_array($PAGE->pagetype, array('mod-quiz-attempt', 'mod-quiz-summary'), true)) {
            return;
        }

        // Ensure we're logged in and have a quiz attempt context.
        if (!\isloggedin()) {
            return;
        }

        // Moodle 4.3 quiz attempt URLs use cmid, not id.
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        $quizid = optional_param('cmid', 0, PARAM_INT)
            ?: optional_param('id', 0, PARAM_INT)
            ?: optional_param('quizid', 0, PARAM_INT);
        $courseid = $PAGE->course->id ?? 0;

        if ($attemptid <= 0 || $quizid <= 0) {
            return;
        }
        require_once($CFG->dirroot . '/local/quizinteractions/lib.php');
        $totalquestions = \local_quizinteractions_count_real_questions($attemptid);

        // Inject the AMD module call to initialize the logger.
        $PAGE->requires->js_call_amd(
            'local_quizinteractions/clicklogger',
            'init',
            array($attemptid, $quizid, $courseid, $totalquestions)
        );
    }
}
