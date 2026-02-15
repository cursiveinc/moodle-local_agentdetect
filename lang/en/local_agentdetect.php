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
 * Language strings for local_agentdetect.
 *
 * @package    local_agentdetect
 * @copyright  2024 Your Institution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Agent Detection';
$string['privacy:metadata:local_agentdetect_signals'] = 'Stores browser fingerprint and interaction signals for agent detection.';
$string['privacy:metadata:local_agentdetect_signals:userid'] = 'The ID of the user whose session is being analyzed.';
$string['privacy:metadata:local_agentdetect_signals:signals'] = 'JSON-encoded detection signals collected from the browser.';
$string['privacy:metadata:local_agentdetect_signals:agentscore'] = 'Calculated probability score indicating automated browser usage.';
$string['privacy:metadata:local_agentdetect_signals:timecreated'] = 'Timestamp when the signals were collected.';

// Detection signal descriptions.
$string['signal:webdriver'] = 'WebDriver flag detected';
$string['signal:headless'] = 'Headless browser indicators';
$string['signal:extension'] = 'Known automation extension detected';
$string['signal:interaction'] = 'Anomalous interaction patterns';
$string['signal:fingerprint'] = 'Suspicious browser fingerprint';

// Admin settings.
$string['settings:enabled'] = 'Enable agent detection';
$string['settings:enabled_desc'] = 'When enabled, the plugin will collect browser signals and flag potential automated sessions.';
$string['settings:threshold'] = 'Detection threshold';
$string['settings:threshold_desc'] = 'Agent probability score (0-100) above which a session is flagged. Lower values are more sensitive.';
$string['settings:collectinteraction'] = 'Collect interaction data';
$string['settings:collectinteraction_desc'] = 'Track mouse movements, click patterns, and keystroke timing for behavioral analysis.';
