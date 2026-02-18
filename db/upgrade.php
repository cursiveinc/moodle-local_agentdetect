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
 * Upgrade steps for local_agentdetect.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the upgrade steps from the given old version.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool True on success.
 */
function xmldb_local_agentdetect_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026021600) {
        // Add userid_sessionid index to signals table.
        $table = new xmldb_table('local_agentdetect_signals');
        $index = new xmldb_index('userid_sessionid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'sessionid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Add timemodified index to flags table.
        $table = new xmldb_table('local_agentdetect_flags');
        $index = new xmldb_index('timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Add contextid index to flags table.
        $index = new xmldb_index('contextid', XMLDB_INDEX_NOTUNIQUE, ['contextid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026021600, 'local', 'agentdetect');
    }

    if ($oldversion < 2026021700) {
        // Version 0.3.0: Added backup/restore API, event classes, riskbitmasks on capabilities.
        // No database schema changes — only capability and code-level additions.
        upgrade_plugin_savepoint(true, 2026021700, 'local', 'agentdetect');
    }

    if ($oldversion < 2026021800) {
        // Version 0.3.1: Security hardening — added RISK_PERSONAL to manageflags capability.
        // No database schema changes.
        upgrade_plugin_savepoint(true, 2026021800, 'local', 'agentdetect');
    }

    return true;
}
