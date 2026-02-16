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
 * External API for retrieving user detection flags.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_agentdetect\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context;

/**
 * External function for retrieving user detection flags.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_flags extends external_api {
    /**
     * Describes the parameters for this function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'User ID'),
                'List of user IDs to check'
            ),
            'contextid' => new external_value(PARAM_INT, 'Context ID (course or module)'),
        ]);
    }

    /**
     * Get detection flags for the specified users in the given context.
     *
     * Searches the given context and all child contexts (e.g., a course
     * context will find flags from all quiz modules within it).
     *
     * Returns flagged users with their flag data, and also returns
     * users who have been scanned (have signals) but were not flagged,
     * marked as 'likely_human' to allow positive visual confirmation.
     *
     * @param array $userids List of user IDs.
     * @param int $contextid Context ID.
     * @return array Array of flag data for all scanned users.
     */
    public static function execute(array $userids, int $contextid): array {
        global $DB;

        // Parameter validation.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userids' => $userids,
            'contextid' => $contextid,
        ]);

        $context = context::instance_by_id($params['contextid']);
        self::validate_context($context);

        // Check capability.
        require_capability('local/agentdetect:viewreports', $context);

        if (empty($params['userids'])) {
            return [];
        }

        // Get the context and all child context IDs.
        $contextids = self::get_context_and_children_ids($context);

        // Build query for flags in these contexts (including NULL contextid for system-level flags).
        list($userinsql, $userparams) = $DB->get_in_or_equal($params['userids'], SQL_PARAMS_NAMED, 'uid');
        list($ctxinsql, $ctxparams) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

        $sql = "SELECT f.userid, f.flagtype, f.maxscore, f.detectioncount
                  FROM {local_agentdetect_flags} f
                 WHERE f.userid {$userinsql}
                   AND (f.contextid {$ctxinsql} OR f.contextid IS NULL)
                   AND f.flagtype != 'cleared'
              ORDER BY f.maxscore DESC";

        $flags = $DB->get_records_sql($sql, array_merge($userparams, $ctxparams));

        // Deduplicate: keep the highest-scoring flag per user.
        $result = [];
        $seen = [];
        foreach ($flags as $flag) {
            if (isset($seen[$flag->userid])) {
                continue;
            }
            $seen[$flag->userid] = true;
            $result[] = [
                'userid' => (int) $flag->userid,
                'flagtype' => $flag->flagtype,
                'maxscore' => (int) $flag->maxscore,
                'detectioncount' => (int) $flag->detectioncount,
            ];
        }

        // Find users who have signals but no flags — these are "likely human".
        $unflaggeduserids = array_diff($params['userids'], array_keys($seen));
        if (!empty($unflaggeduserids)) {
            $likelyhuman = self::get_scanned_unflagged_users($unflaggeduserids, $contextids);
            $result = array_merge($result, $likelyhuman);
        }

        return $result;
    }

    /**
     * Find users who have detection signals but were not flagged.
     *
     * These users were scanned by the detection engine and scored below
     * the suspicious threshold — they are "likely human". Returns them
     * with flagtype 'likely_human' for positive visual confirmation.
     *
     * @param array $userids User IDs to check (already confirmed as unflagged).
     * @param array $contextids Context IDs to search (parent + children).
     * @return array Array of likely_human pseudo-flag entries.
     */
    private static function get_scanned_unflagged_users(array $userids, array $contextids): array {
        global $DB;

        list($userinsql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'suid');
        list($ctxinsql, $ctxparams) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'sctx');

        // Get the max combined score for each unflagged user who has signals.
        $sql = "SELECT s.userid, MAX(s.combinedscore) AS maxscore, COUNT(*) AS signalcount
                  FROM {local_agentdetect_signals} s
                 WHERE s.userid {$userinsql}
                   AND (s.contextid {$ctxinsql} OR s.contextid IS NULL)
              GROUP BY s.userid";

        $signals = $DB->get_records_sql($sql, array_merge($userparams, $ctxparams));

        $result = [];
        foreach ($signals as $signal) {
            $result[] = [
                'userid' => (int) $signal->userid,
                'flagtype' => 'likely_human',
                'maxscore' => (int) $signal->maxscore,
                'detectioncount' => 0,
            ];
        }

        return $result;
    }

    /**
     * Get context ID and all child context IDs.
     *
     * @param context $context The parent context.
     * @return array Array of context IDs.
     */
    private static function get_context_and_children_ids(context $context): array {
        global $DB;

        $path = $context->path;
        $like = $DB->sql_like('path', ':path');
        $params = ['path' => $path . '/%', 'selfid' => $context->id];

        $childids = $DB->get_fieldset_select('context', 'id', "{$like} OR id = :selfid", $params);

        return $childids ?: [$context->id];
    }

    /**
     * Describes the return value for this function.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'User ID'),
                'flagtype' => new external_value(PARAM_ALPHANUMEXT, 'Flag type'),
                'maxscore' => new external_value(PARAM_INT, 'Maximum detection score'),
                'detectioncount' => new external_value(PARAM_INT, 'Number of detections'),
            ])
        );
    }
}
