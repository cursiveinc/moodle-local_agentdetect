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
 * Backup plugin for local_agentdetect.
 *
 * Backs up agent detection signals and flags associated with a course.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Backup plugin class for local_agentdetect on the course level.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_agentdetect_plugin extends backup_local_plugin {
    /**
     * Define the plugin structure for backup.
     *
     * @return backup_plugin_element The plugin element.
     */
    protected function define_course_plugin_structure() {
        // Define the virtual plugin element with the condition to fulfil.
        $plugin = $this->get_plugin_element(null, null, null);

        // Create one standard named plugin element (the visible container).
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());

        // Connect the visible container ASAP.
        $plugin->add_child($pluginwrapper);

        // Define each element and its attributes.
        $signals = new backup_nested_element('agentdetect_signals');
        $signal = new backup_nested_element('signal', ['id'], [
            'userid', 'contextid', 'sessionid', 'signaltype',
            'fingerprintscore', 'interactionscore', 'combinedscore',
            'verdict', 'signaldata', 'useragent', 'ipaddress', 'timecreated',
        ]);

        $flags = new backup_nested_element('agentdetect_flags');
        $flag = new backup_nested_element('flag', ['id'], [
            'userid', 'contextid', 'flagtype', 'maxscore',
            'detectioncount', 'lastsessionid', 'notes',
            'flaggedby', 'timecreated', 'timemodified',
        ]);

        // Build the tree.
        $pluginwrapper->add_child($signals);
        $signals->add_child($signal);
        $pluginwrapper->add_child($flags);
        $flags->add_child($flag);

        // Define sources — get data scoped to the course context and its children.
        $coursecontext = context_course::instance($this->task->get_courseid());

        $signal->set_source_sql(
            "SELECT s.*
               FROM {local_agentdetect_signals} s
               JOIN {context} ctx ON ctx.id = s.contextid
              WHERE ctx.path LIKE :pathlike
                 OR s.contextid = :contextid",
            [
                'pathlike' => backup_helper::is_sqlparam($coursecontext->path . '/%'),
                'contextid' => backup_helper::is_sqlparam($coursecontext->id),
            ]
        );

        $flag->set_source_sql(
            "SELECT f.*
               FROM {local_agentdetect_flags} f
               JOIN {context} ctx ON ctx.id = f.contextid
              WHERE ctx.path LIKE :pathlike
                 OR f.contextid = :contextid",
            [
                'pathlike' => backup_helper::is_sqlparam($coursecontext->path . '/%'),
                'contextid' => backup_helper::is_sqlparam($coursecontext->id),
            ]
        );

        // Define userid annotations so users can be mapped during restore.
        $signal->annotate_ids('user', 'userid');
        $flag->annotate_ids('user', 'userid');
        $flag->annotate_ids('user', 'flaggedby');

        return $plugin;
    }
}
