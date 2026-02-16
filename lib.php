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
 * Library functions for local_agentdetect.
 *
 * @package    local_agentdetect
 * @copyright  2024 Your Institution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Inject agent detection JavaScript into pages.
 *
 * This function is called via the before_footer callback.
 *
 * @return string Empty string (JS is loaded via page requirements).
 */
function local_agentdetect_before_footer(): string {
    global $PAGE, $USER;

    // Check if detection is enabled.
    if (!get_config('local_agentdetect', 'enabled')) {
        return '';
    }

    // Don't run for guests or not-logged-in users.
    if (!isloggedin() || isguestuser()) {
        return '';
    }

    // Check if we should run on this page type.
    $enabledpagetypes = get_config('local_agentdetect', 'pagetypes');
    if (!empty($enabledpagetypes)) {
        $types = explode(',', $enabledpagetypes);
        $currenttype = $PAGE->pagetype;
        $matches = false;
        foreach ($types as $type) {
            $type = trim($type);
            if (empty($type)) {
                continue;
            }
            // Support wildcards like mod-assign-*.
            $pattern = '/^' . str_replace(['*', '-'], ['.*', '\-'], $type) . '$/';
            if (preg_match($pattern, $currenttype)) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            return '';
        }
    }

    // Get context.
    $context = $PAGE->context;

    // Prepare configuration for JavaScript.
    $config = [
        'enabled' => true,
        'reportInterval' => (int) get_config('local_agentdetect', 'reportinterval') ?: 30000,
        'minReportScore' => (int) get_config('local_agentdetect', 'minreportscore') ?: 10,
        'contextId' => $context->id,
        'sessionKey' => sesskey(),
        'debug' => (bool) get_config('local_agentdetect', 'debug'),
    ];

    // Load the detector module.
    $PAGE->requires->js_call_amd(
        'local_agentdetect/detector',
        'init',
        [$config]
    );

    return '';
}

/**
 * Add navigation nodes for admin access.
 *
 * @param navigation_node $nav The navigation node to extend.
 */
function local_agentdetect_extend_navigation(navigation_node $nav): void {
    // Navigation extension would go here if needed.
}

/**
 * Extend settings navigation for the plugin.
 *
 * @param settings_navigation $settingsnav The settings navigation object.
 * @param context $context The context.
 */
function local_agentdetect_extend_settings_navigation(
    settings_navigation $settingsnav,
    context $context
): void {
    global $PAGE;

    // Only add for course and module contexts where user can view reports.
    if (!has_capability('local/agentdetect:viewreports', $context)) {
        return;
    }

    // Find the course/activity settings node.
    $settingsnode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE);
    if (!$settingsnode) {
        $settingsnode = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    }

    if ($settingsnode) {
        $url = new moodle_url('/local/agentdetect/report.php', ['contextid' => $context->id]);
        $settingsnode->add(
            get_string('pluginname', 'local_agentdetect'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'agentdetect',
            new pix_icon('i/report', '')
        );
    }
}
