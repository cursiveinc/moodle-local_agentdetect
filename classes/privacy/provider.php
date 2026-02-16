<?php
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
 * Privacy provider for local_agentdetect.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_agentdetect\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider implementation for local_agentdetect.
 *
 * This plugin stores personal data including user IDs, IP addresses,
 * user agent strings, and behavioral detection signals.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the types of data stored by this plugin.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_agentdetect_signals', [
            'userid' => 'privacy:metadata:local_agentdetect_signals:userid',
            'contextid' => 'privacy:metadata:local_agentdetect_signals:contextid',
            'sessionid' => 'privacy:metadata:local_agentdetect_signals:sessionid',
            'signaltype' => 'privacy:metadata:local_agentdetect_signals:signaltype',
            'fingerprintscore' => 'privacy:metadata:local_agentdetect_signals:fingerprintscore',
            'interactionscore' => 'privacy:metadata:local_agentdetect_signals:interactionscore',
            'combinedscore' => 'privacy:metadata:local_agentdetect_signals:combinedscore',
            'verdict' => 'privacy:metadata:local_agentdetect_signals:verdict',
            'signaldata' => 'privacy:metadata:local_agentdetect_signals:signaldata',
            'useragent' => 'privacy:metadata:local_agentdetect_signals:useragent',
            'ipaddress' => 'privacy:metadata:local_agentdetect_signals:ipaddress',
            'timecreated' => 'privacy:metadata:local_agentdetect_signals:timecreated',
        ], 'privacy:metadata:local_agentdetect_signals');

        $collection->add_database_table('local_agentdetect_flags', [
            'userid' => 'privacy:metadata:local_agentdetect_flags:userid',
            'contextid' => 'privacy:metadata:local_agentdetect_flags:contextid',
            'flagtype' => 'privacy:metadata:local_agentdetect_flags:flagtype',
            'maxscore' => 'privacy:metadata:local_agentdetect_flags:maxscore',
            'detectioncount' => 'privacy:metadata:local_agentdetect_flags:detectioncount',
            'notes' => 'privacy:metadata:local_agentdetect_flags:notes',
            'timecreated' => 'privacy:metadata:local_agentdetect_flags:timecreated',
            'timemodified' => 'privacy:metadata:local_agentdetect_flags:timemodified',
        ], 'privacy:metadata:local_agentdetect_flags');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Signals with a context.
        $sql = "SELECT DISTINCT contextid FROM {local_agentdetect_signals}
                 WHERE userid = :userid1 AND contextid IS NOT NULL";
        $contextlist->add_from_sql($sql, ['userid1' => $userid]);

        // Flags with a context.
        $sql = "SELECT DISTINCT contextid FROM {local_agentdetect_flags}
                 WHERE userid = :userid1 AND contextid IS NOT NULL";
        $contextlist->add_from_sql($sql, ['userid1' => $userid]);

        // Always include system context for records without a specific context.
        $contextlist->add_system_context();

        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist The userlist containing the list of users.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_system) {
            // Get all users with signals or flags.
            $sql = "SELECT DISTINCT userid FROM {local_agentdetect_signals}";
            $userlist->add_from_sql('userid', $sql, []);

            $sql = "SELECT DISTINCT userid FROM {local_agentdetect_flags}";
            $userlist->add_from_sql('userid', $sql, []);
        } else {
            // Get users with signals or flags in this specific context.
            $sql = "SELECT DISTINCT userid FROM {local_agentdetect_signals}
                     WHERE contextid = :contextid";
            $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);

            $sql = "SELECT DISTINCT userid FROM {local_agentdetect_flags}
                     WHERE contextid = :contextid";
            $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);
        }
    }

    /**
     * Export personal data for the given approved_contextlist.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $subcontext = [get_string('pluginname', 'local_agentdetect')];

            if ($context instanceof \context_system) {
                // Export signals without specific context.
                $signals = $DB->get_records_select(
                    'local_agentdetect_signals',
                    'userid = :userid AND (contextid IS NULL OR contextid = :contextid)',
                    ['userid' => $userid, 'contextid' => $context->id]
                );
            } else {
                $signals = $DB->get_records(
                    'local_agentdetect_signals',
                    ['userid' => $userid, 'contextid' => $context->id]
                );
            }

            if ($signals) {
                $exportdata = [];
                foreach ($signals as $signal) {
                    $exportdata[] = (object) [
                        'sessionid' => $signal->sessionid,
                        'signaltype' => $signal->signaltype,
                        'fingerprintscore' => $signal->fingerprintscore,
                        'interactionscore' => $signal->interactionscore,
                        'combinedscore' => $signal->combinedscore,
                        'verdict' => $signal->verdict,
                        'useragent' => $signal->useragent,
                        'ipaddress' => $signal->ipaddress,
                        'timecreated' => \core_privacy\local\request\transform::datetime($signal->timecreated),
                    ];
                }
                writer::with_context($context)->export_data(
                    array_merge($subcontext, ['signals']),
                    (object) ['signals' => $exportdata]
                );
            }

            // Export flags.
            if ($context instanceof \context_system) {
                $flags = $DB->get_records_select(
                    'local_agentdetect_flags',
                    'userid = :userid AND (contextid IS NULL OR contextid = :contextid)',
                    ['userid' => $userid, 'contextid' => $context->id]
                );
            } else {
                $flags = $DB->get_records(
                    'local_agentdetect_flags',
                    ['userid' => $userid, 'contextid' => $context->id]
                );
            }

            if ($flags) {
                $exportdata = [];
                foreach ($flags as $flag) {
                    $exportdata[] = (object) [
                        'flagtype' => $flag->flagtype,
                        'maxscore' => $flag->maxscore,
                        'detectioncount' => $flag->detectioncount,
                        'notes' => $flag->notes,
                        'timecreated' => \core_privacy\local\request\transform::datetime($flag->timecreated),
                        'timemodified' => \core_privacy\local\request\transform::datetime($flag->timemodified),
                    ];
                }
                writer::with_context($context)->export_data(
                    array_merge($subcontext, ['flags']),
                    (object) ['flags' => $exportdata]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_system) {
            $DB->delete_records('local_agentdetect_signals');
            $DB->delete_records('local_agentdetect_flags');
        } else {
            $DB->delete_records('local_agentdetect_signals', ['contextid' => $context->id]);
            $DB->delete_records('local_agentdetect_flags', ['contextid' => $context->id]);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $DB->delete_records('local_agentdetect_signals', ['userid' => $userid]);
                $DB->delete_records('local_agentdetect_flags', ['userid' => $userid]);
            } else {
                $DB->delete_records('local_agentdetect_signals', [
                    'userid' => $userid,
                    'contextid' => $context->id,
                ]);
                $DB->delete_records('local_agentdetect_flags', [
                    'userid' => $userid,
                    'contextid' => $context->id,
                ]);
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        if ($context instanceof \context_system) {
            $DB->delete_records_select('local_agentdetect_signals', "userid {$insql}", $params);
            $DB->delete_records_select('local_agentdetect_flags', "userid {$insql}", $params);
        } else {
            $params['contextid'] = $context->id;
            $DB->delete_records_select(
                'local_agentdetect_signals',
                "userid {$insql} AND contextid = :contextid",
                $params
            );
            $DB->delete_records_select(
                'local_agentdetect_flags',
                "userid {$insql} AND contextid = :contextid",
                $params
            );
        }
    }
}
