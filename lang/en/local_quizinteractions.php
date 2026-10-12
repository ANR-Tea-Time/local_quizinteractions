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
 * Plugin strings are defined here.
 *
 * @package     local_quizinteractions
 * @category    string
 * @copyright   2026 Mohamed Hamza Falih <Mohamed_Hamza.Falih@univ-lemans.fr>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Quiz Interactions';

// Privacy strings.
$string['privacy:metadata:quizinteractionslog'] = 'Logs of student interactions with quiz answer options during quiz attempts.';
$string['privacy:metadata:quizinteractionslog:attemptid'] = 'The ID of the quiz attempt.';
$string['privacy:metadata:quizinteractionslog:userid'] = 'The ID of the user who made the attempt.';
$string['privacy:metadata:quizinteractionslog:quizid'] = 'The ID of the quiz.';
$string['privacy:metadata:quizinteractionslog:timecreated'] = 'The timestamp when the interactions log was created.';
$string['privacy:metadata:quizinteractionslog:interactions'] = 'A JSON array containing detailed interaction records (which answer option was selected/checked, at what position, and at what timestamp). This is personal behavioral data.';
