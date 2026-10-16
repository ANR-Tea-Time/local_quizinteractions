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
 * External web service for logging quiz interactions.
 *
 * @package     local_quizinteractions
 * @copyright   2026 Mohamed Hamza Falih <Mohamed_Hamza.Falih@univ-lemans.fr>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizinteractions\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use restricted_context_exception;

/**
 * External API for logging quiz interactions.
 */
class log_interactions extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            array(
                'attemptid' => new external_value(PARAM_INT, 'Quiz attempt ID', VALUE_REQUIRED),
                'quizid' => new external_value(PARAM_INT, 'Quiz module instance ID', VALUE_REQUIRED),
                'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
                'interactions' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt ID', VALUE_REQUIRED),
                            'elementname' => new external_value(
                                PARAM_RAW,
                                'Name attribute of the changed form element',
                                VALUE_OPTIONAL
                            ),
                            'slot' => new external_value(PARAM_INT, 'Question slot number', VALUE_REQUIRED),
                            'questionid' => new external_value(PARAM_INT, 'Question ID', VALUE_OPTIONAL),
                            'answerid' => new external_value(PARAM_RAW, 'Answer ID or value', VALUE_OPTIONAL),
                            'position' => new external_value(PARAM_INT, 'Position index of answer option', VALUE_OPTIONAL),
                            'action' => new external_value(
                                PARAM_ALPHA,
                                'Action type: checked, unchecked, or selected',
                                VALUE_OPTIONAL
                            ),
                            'eventtype' => new external_value(
                                PARAM_ALPHA,
                                'Interaction event type',
                                VALUE_OPTIONAL,
                                'answer'
                            ),
                            'dropdownclicktimestamp' => new external_value(
                                PARAM_INT,
                                'Timestamp in milliseconds when a dropdown was opened',
                                VALUE_OPTIONAL
                            ),
                            'targetslot' => new external_value(
                                PARAM_INT,
                                'Target question slot for navigation events',
                                VALUE_OPTIONAL
                            ),
                            'totalquestions' => new external_value(
                                PARAM_INT,
                                'Number of real questions in the quiz',
                                VALUE_OPTIONAL
                            ),
                            'dragstarttimestamp' => new external_value(
                                PARAM_INT,
                                'Timestamp in milliseconds when a drag started',
                                VALUE_OPTIONAL
                            ),
                            'droptimestamp' => new external_value(
                                PARAM_INT,
                                'Timestamp in milliseconds when a drag was released',
                                VALUE_OPTIONAL
                            ),
                            'fromplace' => new external_value(
                                PARAM_RAW,
                                'Origin of the dragged answer: pool or gap number',
                                VALUE_OPTIONAL
                            ),
                            'toplace' => new external_value(
                                PARAM_RAW,
                                'Destination of the dragged answer: pool or gap number',
                                VALUE_OPTIONAL
                            ),
                            'cardtext' => new external_value(
                                PARAM_RAW,
                                'Visible text of the dragged answer',
                                VALUE_OPTIONAL
                            ),
                            'timestamp' => new external_value(PARAM_INT, 'Timestamp in milliseconds', VALUE_REQUIRED),
                            'datetime' => new external_value(
                                PARAM_TEXT,
                                'Timestamp in ISO 8601 format',
                                VALUE_OPTIONAL,
                                ''
                            ),
                        )
                    ),
                    'Interaction objects',
                    VALUE_REQUIRED
                ),
            )
        );
    }

    /**
     * Execute the web service function.
     *
     * @param int $attemptid
     * @param int $quizid
     * @param int $courseid
     * @param array $interactions
     * @return array
     */
    public static function execute(int $attemptid, int $quizid, int $courseid, array $interactions): array {
        global $DB, $USER, $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), array(
            'attemptid' => $attemptid,
            'quizid' => $quizid,
            'courseid' => $courseid,
            'interactions' => $interactions,
        ));

        // In Moodle 4.3, the quiz attempt URLs use the course module id (cmid), not the quiz instance id.
        // Use the course module context and resolve the actual quiz instance from the module record.
        $context = \context_module::instance($quizid);
        self::validate_context($context);

        require_capability('mod/quiz:attempt', $context);

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $cm = get_coursemodule_from_id('quiz', $quizid, 0, false, MUST_EXIST);
        $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
        $attempt = $DB->get_record('quiz_attempts', array('id' => $attemptid), '*', MUST_EXIST);

        if ((int) $attempt->userid !== (int) $USER->id) {
            throw new restricted_context_exception();
        }

        if ((int) $attempt->quiz !== (int) $quiz->id) {
            throw new invalid_parameter_exception('The attempted quiz does not match the course module.');
        }

        if (!in_array($attempt->state, array('inprogress', 'finished'))) {
            throw new invalid_parameter_exception('Attempt is not in a valid state for logging interactions.');
        }

        $interactionsjson = json_encode($params['interactions']);
        $timecreated = time();

        $existing = $DB->get_record('local_quizinteractions_log', array('attemptid' => $attemptid));

        if ($existing) {
            $existing->interactions = $interactionsjson;
            $existing->timecreated = $timecreated;
            $existing->userid = $USER->id;
            $existing->quizid = $quiz->id;
            $DB->update_record('local_quizinteractions_log', $existing);
        } else {
            $record = new \stdClass();
            $record->attemptid = $attemptid;
            $record->userid = $USER->id;
            $record->quizid = $quiz->id;
            $record->timecreated = $timecreated;
            $record->interactions = $interactionsjson;
            $DB->insert_record('local_quizinteractions_log', $record);
        }

        return array(
            'status' => 'ok',
        );
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_ALPHA, 'Status of the operation'),
            )
        );
    }
}
