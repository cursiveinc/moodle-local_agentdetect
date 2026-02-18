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
 * Beacon endpoint for page unload signal reporting.
 *
 * This endpoint receives signals sent via navigator.sendBeacon()
 * during page unload events.
 *
 * @package    local_agentdetect
 * @copyright  2026 Joseph Thibault <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Minimal bootstrap for performance.
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');
require_login();

// Read beacon data.
$input = file_get_contents('php://input');
if (empty($input)) {
    http_response_code(400);
    exit;
}

$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit;
}

// Validate required fields.
$required = ['sesskey', 'sessionid', 'signaltype', 'signaldata'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        exit;
    }
}

// Validate session.
if (!confirm_sesskey($data['sesskey'])) {
    http_response_code(403);
    exit;
}

// Decode signal data.
$signaldata = json_decode($data['signaldata'], true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit;
}

// Sanitise input fields.
$sessionid = clean_param($data['sessionid'], PARAM_ALPHANUMEXT);
$signaltype = clean_param($data['signaltype'], PARAM_ALPHA);
$contextid = clean_param($data['contextid'] ?? 0, PARAM_INT);

// Validate context exists, fall back to system context (0) if not.
if ($contextid > 0) {
    $ctx = \context::instance_by_id($contextid, IGNORE_MISSING);
    if (!$ctx) {
        $contextid = 0;
    }
}

// Store the signal.
try {
    $manager = new \local_agentdetect\signal_manager();
    $manager->store_signal(
        $USER->id,
        $contextid,
        $sessionid,
        $signaltype,
        $signaldata
    );

    http_response_code(204); // No content - success.
} catch (Exception $e) {
    http_response_code(500);
}
