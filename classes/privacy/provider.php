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
 * Privacy provider for the local_quizinteractions plugin.
 *
 * @package     local_quizinteractions
 * @copyright   2026 Mohamed Hamza Falih <Mohamed_Hamza.Falih@univ-lemans.fr>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_quizinteractions\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\deletion_criteria;

/**
 * Privacy provider for the local_quizinteractions plugin.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Get metadata about user data in this plugin.
     *
     * @param collection $items The metadata collection to add to.
     * @return collection The metadata collection.
     */
    public static function get_metadata(collection $items): collection {
        $items->add_database_table(
            'local_quizinteractions_log',
            array(
                'attemptid' => 'privacy:metadata:quizinteractionslog:attemptid',
                'userid' => 'privacy:metadata:quizinteractionslog:userid',
                'quizid' => 'privacy:metadata:quizinteractionslog:quizid',
                'timecreated' => 'privacy:metadata:quizinteractionslog:timecreated',
                'interactions' => 'privacy:metadata:quizinteractionslog:interactions',
            ),
            'privacy:metadata:quizinteractionslog'
        );

        return $items;
    }

    /**
     * Get the list of contexts that contain user data for the specified user.
     *
     * @param int $userid The user ID.
     * @return contextlist The context list containing all contexts with user data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT c.id
                FROM {local_quizinteractions_log} qil
                JOIN {quiz} q ON qil.quizid = q.id
                JOIN {course_modules} cm ON q.id = cm.instance AND cm.module = (
                    SELECT id FROM {modules} WHERE name = 'quiz'
                )
                JOIN {context} c ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                WHERE qil.userid = :userid";

        $contextlist->add_from_sql($sql, array(
            'userid' => $userid,
            'contextlevel' => CONTEXT_MODULE,
        ));

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add user IDs to.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        // Check if this is a quiz context.
        $sql = "SELECT DISTINCT qil.userid
                FROM {local_quizinteractions_log} qil
                JOIN {quiz} q ON qil.quizid = q.id
                JOIN {course_modules} cm ON q.id = cm.instance
                WHERE cm.id = :cmid AND cm.module = (
                    SELECT id FROM {modules} WHERE name = 'quiz'
                )";

        $userlist->add_from_sql('userid', $sql, array('cmid' => $context->instanceid));
    }

    /**
     * Export user data for a list of contexts.
     *
     * @param approved_contextlist $contextlist The list of contexts for which to export data.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }

            // Get the quiz ID from the course module.
            $sql = "SELECT q.id FROM {quiz} q
                    JOIN {course_modules} cm ON q.id = cm.instance
                    WHERE cm.id = :cmid AND cm.module = (
                        SELECT id FROM {modules} WHERE name = 'quiz'
                    )";
            $quizid = $DB->get_field_sql($sql, array('cmid' => $context->instanceid));

            if (!$quizid) {
                continue;
            }

            // Get the user's interaction logs for this quiz.
            $logs = $DB->get_records('local_quizinteractions_log', array(
                'userid' => $userid,
                'quizid' => $quizid,
            ));

            if (empty($logs)) {
                continue;
            }

            $data = array();
            foreach ($logs as $log) {
                $logdata = new \stdClass();
                $logdata->attemptid = $log->attemptid;
                $logdata->timecreated = (int) $log->timecreated;
                $logdata->interactions = json_decode($log->interactions, true);
                $data[] = $logdata;
            }

            writer::with_context($context)
                ->export_data(array(get_string('pluginname', 'local_quizinteractions')), (object)['logs' => $data]);
        }
    }

    /**
     * Delete all user data for a list of contexts.
     *
     * @param approved_contextlist $contextlist The list of contexts for which to delete data.
     */
    public static function delete_data_for_contexts(approved_contextlist $contextlist) {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }

            // Get the quiz ID from the course module.
            $sql = "SELECT q.id FROM {quiz} q
                    JOIN {course_modules} cm ON q.id = cm.instance
                    WHERE cm.id = :cmid AND cm.module = (
                        SELECT id FROM {modules} WHERE name = 'quiz'
                    )";
            $quizid = $DB->get_field_sql($sql, array('cmid' => $context->instanceid));

            if ($quizid) {
                $DB->delete_records('local_quizinteractions_log', array('quizid' => $quizid));
            }
        }
    }

    /**
     * Delete all user data for a specific user.
     *
     * @param approved_userlist $userlist The list of users whose data should be deleted.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $userids = $userlist->get_userids();
        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        // Get the quiz ID from the course module.
        $sql = "SELECT q.id FROM {quiz} q
                JOIN {course_modules} cm ON q.id = cm.instance
                WHERE cm.id = :cmid AND cm.module = (
                    SELECT id FROM {modules} WHERE name = 'quiz'
                )";
        $quizid = $DB->get_field_sql($sql, array('cmid' => $context->instanceid));

        if ($quizid) {
            list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $DB->delete_records_select(
                'local_quizinteractions_log',
                "userid {$insql} AND quizid = :quizid",
                array_merge($inparams, array('quizid' => $quizid))
            );
        }
    }
}
