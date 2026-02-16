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
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
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
        get_string('settings:minreportscore', 'local_agentdetect'),
        get_string('settings:minreportscore_desc', 'local_agentdetect'),
        10,
        PARAM_INT
    ));

    // Report interval.
    $settings->add(new admin_setting_configtext(
        'local_agentdetect/reportinterval',
        get_string('settings:reportinterval', 'local_agentdetect'),
        get_string('settings:reportinterval_desc', 'local_agentdetect'),
        30000,
        PARAM_INT
    ));

    // Page types to monitor.
    $settings->add(new admin_setting_configtextarea(
        'local_agentdetect/pagetypes',
        get_string('settings:pagetypes', 'local_agentdetect'),
        get_string('settings:pagetypes_desc', 'local_agentdetect'),
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
        get_string('settings:debug', 'local_agentdetect'),
        get_string('settings:debug_desc', 'local_agentdetect'),
        0
    ));

    $ADMIN->add('localplugins', $settings);

    // Register the admin report under Site Administration > Reports.
    $ADMIN->add('reports', new admin_externalpage(
        'local_agentdetect_report',
        get_string('pluginname', 'local_agentdetect'),
        new moodle_url('/local/agentdetect/report.php'),
        'local/agentdetect:viewsignals'
    ));
}
