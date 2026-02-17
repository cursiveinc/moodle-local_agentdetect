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
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Inject agent detection JavaScript into pages.
 *
 * This function is called via the before_footer callback.
 * It handles two concerns:
 * 1. Loading the detection engine on monitored page types.
 * 2. Loading quiz badge icons for teachers on quiz report/review pages.
 *
 * @return string Empty string (JS is loaded via page requirements).
 */
function local_agentdetect_before_footer(): string {
    global $PAGE, $USER;

    // Don't run for guests or not-logged-in users.
    if (!isloggedin() || isguestuser()) {
        return '';
    }

    // Detection engine.
    $detectionenabled = get_config('local_agentdetect', 'enabled');
    if ($detectionenabled) {
        local_agentdetect_load_detector();
    }

    // Quiz badge injection for teachers.
    local_agentdetect_load_quiz_badges();

    return '';
}

/**
 * Load the detection engine on monitored page types.
 */
function local_agentdetect_load_detector(): void {
    global $PAGE;

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
            return;
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
}

/**
 * Load quiz badge icons on quiz report/review pages for teachers.
 *
 * This runs independently of whether detection is enabled — teachers
 * can see badges even when detection is turned off.
 */
function local_agentdetect_load_quiz_badges(): void {
    global $PAGE;

    $pagetype = $PAGE->pagetype;

    // Only load on quiz report overview or single attempt review.
    if ($pagetype === 'mod-quiz-report' || $pagetype === 'mod-quiz-report-overview') {
        $mode = 'overview';
    } else if ($pagetype === 'mod-quiz-review') {
        $mode = 'review';
    } else {
        return;
    }

    // Walk up to course context.
    $context = $PAGE->context;
    $coursecontext = $context->get_course_context(false);
    if (!$coursecontext) {
        return;
    }

    // Check capability.
    if (!has_capability('local/agentdetect:viewreports', $coursecontext)) {
        return;
    }

    $courseid = $coursecontext->instanceid;
    $reporturl = new moodle_url('/local/agentdetect/coursereport.php', ['courseid' => $courseid]);

    $config = [
        'mode' => $mode,
        'courseid' => $courseid,
        'contextid' => $coursecontext->id,
        'reportUrl' => $reporturl->out(false),
    ];

    $PAGE->requires->js_call_amd(
        'local_agentdetect/quiz_badge',
        'init',
        [$config]
    );
}

/**
 * Extend course navigation with agent detection report link.
 *
 * Adds "Agent Detection Report" to the course Reports section.
 *
 * @param navigation_node $navigation The navigation node.
 * @param stdClass $course The course object.
 * @param context $context The course context.
 */
function local_agentdetect_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context $context
): void {
    if (!has_capability('local/agentdetect:viewreports', $context)) {
        return;
    }

    $url = new moodle_url('/local/agentdetect/coursereport.php', ['courseid' => $course->id]);
    $navigation->add(
        get_string('coursereport', 'local_agentdetect'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'agentdetect_coursereport',
        new pix_icon('i/report', '')
    );
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
 * Intentionally left empty — the course report link is added via
 * local_agentdetect_extend_navigation_course() instead to avoid
 * duplicate navigation entries.
 *
 * @param settings_navigation $settingsnav The settings navigation object.
 * @param context $context The context.
 */
function local_agentdetect_extend_settings_navigation(
    settings_navigation $settingsnav,
    context $context
): void {
    // Course report link handled by extend_navigation_course().
}
