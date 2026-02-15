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
 * Admin settings for local_agentdetect.
 *
 * @package    local_agentdetect
 * @copyright  2024 Your Institution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_agentdetect', get_string('pluginname', 'local_agentdetect'));

    // Enable/disable detection.
    $settings->add(new admin_setting_configcheckbox(
        'local_agentdetect/enabled',
        get_string('settings:enabled', 'local_agentdetect'),
        get_string('settings:enabled_desc', 'local_agentdetect'),
        0
    ));

    // Detection threshold.
    $settings->add(new admin_setting_configtext(
        'local_agentdetect/threshold',
        get_string('settings:threshold', 'local_agentdetect'),
        get_string('settings:threshold_desc', 'local_agentdetect'),
        70,
        PARAM_INT
    ));

    // Minimum score to report.
    $settings->add(new admin_setting_configtext(
        'local_agentdetect/minreportscore',
        'Minimum report score',
        'Only report signals with combined score at or above this value (0-100)',
        10,
        PARAM_INT
    ));

    // Report interval.
    $settings->add(new admin_setting_configtext(
        'local_agentdetect/reportinterval',
        'Report interval (ms)',
        'How often to send detection reports to the server (in milliseconds)',
        30000,
        PARAM_INT
    ));

    // Page types to monitor.
    $settings->add(new admin_setting_configtextarea(
        'local_agentdetect/pagetypes',
        'Page types to monitor',
        'Comma-separated list of page types to enable detection on. Use * for wildcard. ' .
        'Leave empty to monitor all pages. Examples: mod-assign-*, mod-quiz-*, mod-forum-*',
        'mod-assign-*,mod-quiz-*'
    ));

    // Collect interaction data.
    $settings->add(new admin_setting_configcheckbox(
        'local_agentdetect/collectinteraction',
        get_string('settings:collectinteraction', 'local_agentdetect'),
        get_string('settings:collectinteraction_desc', 'local_agentdetect'),
        1
    ));

    // Debug mode.
    $settings->add(new admin_setting_configcheckbox(
        'local_agentdetect/debug',
        'Debug mode',
        'Enable debug logging in browser console',
        0
    ));

    $ADMIN->add('localplugins', $settings);
}
