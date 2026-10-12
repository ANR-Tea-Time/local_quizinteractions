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
 * Web service definitions for the local_quizinteractions plugin.
 *
 * @package     local_quizinteractions
 * @copyright   2026 Mohamed Hamza Falih <Mohamed_Hamza.Falih@univ-lemans.fr>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$services = array(
    'local_quizinteractions' => array(
        'functions' => array(
            'local_quizinteractions_log_interactions',
        ),
        'requiredcapability' => 'mod/quiz:attempt',
        'restrictedusers' => 1,
        'enabled' => 1,
        'ajax' => true,
    ),
);

$functions = array(
    'local_quizinteractions_log_interactions' => array(
        'classname' => 'local_quizinteractions\external\log_interactions',
        'methodname' => 'execute',
        'classpath' => 'local/quizinteractions/classes/external/log_interactions.php',
        'description' => 'Log student interactions with quiz answer options during an attempt.',
        'type' => 'write',
        'ajax' => true,
        'services' => array('local_quizinteractions'),
        'capabilities' => 'mod/quiz:attempt',
    ),
);
