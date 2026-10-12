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
 * Main library functions for the local_quizinteractions plugin.
 * Also contains fallback hooks for older Moodle versions.
 *
 * @package     local_quizinteractions
 * @copyright   2026 Mohamed Hamza Falih <Mohamed_Hamza.Falih@univ-lemans.fr>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Count real questions in a quiz attempt, excluding information/description items.
 *
 * @param int $attemptid The quiz attempt ID.
 * @return int
 */
function local_quizinteractions_count_real_questions(int $attemptid): int {
    global $CFG;

    require_once($CFG->dirroot . '/mod/quiz/locallib.php');
    $attempt = \mod_quiz\quiz_attempt::create($attemptid);
    $totalquestions = 0;

    foreach ($attempt->get_slots() as $slot) {
        if ($attempt->is_real_question($slot)) {
            $totalquestions++;
        }
    }

    return $totalquestions;
}

/**
 * Hook callback for the quiz attempt render (fallback for older Moodle versions).
 * This is called by Moodle's core when rendering quiz attempts.
 *
 * @param \renderer_base $renderer The renderer being used.
 * @param array $data The data passed to the renderer.
 */
function local_quizinteractions_before_quiz_attempt_render(\renderer_base $renderer, array &$data) {
    global $PAGE;

    // The finish form is rendered on the quiz summary page in Moodle 4.3.
    if (!in_array($PAGE->pagetype, array('mod-quiz-attempt', 'mod-quiz-summary'), true)) {
        return;
    }

    // Moodle 4.3 quiz URLs use cmid, not id.
    $attemptid = optional_param('attempt', 0, PARAM_INT);
    $quizid = optional_param('cmid', 0, PARAM_INT)
        ?: optional_param('id', 0, PARAM_INT)
        ?: optional_param('quizid', 0, PARAM_INT);
    $courseid = $PAGE->course->id ?? 0;

    if ($attemptid <= 0 || $quizid <= 0) {
        return;
    }
    $totalquestions = local_quizinteractions_count_real_questions($attemptid);

    // Inject the AMD module call to initialize the logger.
    $PAGE->requires->js_call_amd(
        'local_quizinteractions/clicklogger',
        'init',
        array($attemptid, $quizid, $courseid, $totalquestions)
    );
}

/**
 * Hook into page footer.
 *
 * @return string
 */
function local_quizinteractions_before_footer() {
    global $PAGE;

    // The final-submit form is on mod-quiz-summary in Moodle 4.3.
    if (!in_array($PAGE->pagetype, array('mod-quiz-attempt', 'mod-quiz-summary'), true)) {
        return '';
    }
    if (!isloggedin()) {
        return '';
    }
    $attemptid = optional_param('attempt', 0, PARAM_INT);
    $quizid = optional_param('cmid', 0, PARAM_INT)
        ?: optional_param('id', 0, PARAM_INT)
        ?: optional_param('quizid', 0, PARAM_INT);
    $courseid = $PAGE->course->id ?? 0;
    if ($attemptid <= 0 || $quizid <= 0) {
        return '';
    }
    $totalquestions = local_quizinteractions_count_real_questions($attemptid);

    $PAGE->requires->js_call_amd(
        'local_quizinteractions/clicklogger',
        'init',
        array($attemptid, $quizid, $courseid, $totalquestions)
    );

    return '';
}
