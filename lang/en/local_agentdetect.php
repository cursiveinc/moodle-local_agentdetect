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
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['agentdetect:configure'] = 'Configure agent detection settings';
$string['agentdetect:manageflags'] = 'Manage agent detection flags';
$string['agentdetect:viewreports'] = 'View agent detection reports';
$string['agentdetect:viewsignals'] = 'View detailed detection signal data';
$string['badge:agentconfirmed'] = 'Agent confirmed - detection score {$a}';
$string['badge:agentsuspected'] = 'Agent suspected - detection score {$a}';
$string['badge:likelyhuman'] = 'Likely human - detection score {$a}';
$string['badge:lowsuspicion'] = 'Low suspicion - detection score {$a}';
$string['coursereport'] = 'Agent Detection Report';
$string['coursereport:caveat'] = 'Important: These results are not definitive proof of academic dishonesty. This report is based on automated behavioural analysis of the student\'s browser session, including mouse movement patterns, click behaviour, and keyboard activity. Unusual patterns may have legitimate explanations. Please use this information as one factor among many when making academic integrity decisions.';
$string['coursereport:date'] = 'Date';
$string['coursereport:detectioncount'] = 'Detection count';
$string['coursereport:flaggedstudents'] = 'Flagged students';
$string['coursereport:flagtype'] = 'Flag type';
$string['coursereport:highestscore'] = 'Highest Score';
$string['coursereport:highestverdict'] = 'Highest Verdict';
$string['coursereport:lastdetected'] = 'Last detected';
$string['coursereport:maxscore'] = 'Max score';
$string['coursereport:noexplanation'] = 'No detailed signal data available for this session.';
$string['coursereport:noflags'] = 'No students flagged in this course.';
$string['coursereport:nosignals'] = 'No detection signals found for this student.';
$string['coursereport:score'] = 'Score';
$string['coursereport:sessioncount'] = 'Sessions with Signals';
$string['coursereport:sessionid'] = 'Session';
$string['coursereport:signalcount'] = 'Signals';
$string['coursereport:studentsignals'] = 'Detection signals for {$a}';
$string['coursereport:summary'] = 'Detection Summary';
$string['coursereport:title'] = 'Agent Detection Report';
$string['coursereport:verdict'] = 'Verdict';
$string['coursereport:viewadminreport'] = 'View admin report';
$string['coursereport:viewdetails'] = 'View details';
$string['coursereport:whyflagged'] = 'Why was this session flagged?';
$string['error:debugonly'] = 'This page is only accessible when debug mode is enabled.';
$string['pluginname'] = 'Agent Detection';
$string['privacy:metadata:local_agentdetect_flags'] = 'Stores aggregated agent detection flags per user.';
$string['privacy:metadata:local_agentdetect_flags:contextid'] = 'The Moodle context for this flag.';
$string['privacy:metadata:local_agentdetect_flags:detectioncount'] = 'The number of suspicious detections recorded.';
$string['privacy:metadata:local_agentdetect_flags:flagtype'] = 'The type of flag assigned to the user.';
$string['privacy:metadata:local_agentdetect_flags:maxscore'] = 'The maximum detection score observed for this user.';
$string['privacy:metadata:local_agentdetect_flags:notes'] = 'Admin notes about this flag.';
$string['privacy:metadata:local_agentdetect_flags:timecreated'] = 'Timestamp when the flag was first created.';
$string['privacy:metadata:local_agentdetect_flags:timemodified'] = 'Timestamp when the flag was last updated.';
$string['privacy:metadata:local_agentdetect_flags:userid'] = 'The ID of the flagged user.';
$string['privacy:metadata:local_agentdetect_signals'] = 'Stores browser fingerprint and interaction signals for agent detection.';
$string['privacy:metadata:local_agentdetect_signals:combinedscore'] = 'Combined detection score (0-100).';
$string['privacy:metadata:local_agentdetect_signals:contextid'] = 'The Moodle context where the detection occurred.';
$string['privacy:metadata:local_agentdetect_signals:fingerprintscore'] = 'Browser fingerprint detection score (0-100).';
$string['privacy:metadata:local_agentdetect_signals:interactionscore'] = 'Interaction anomaly detection score (0-100).';
$string['privacy:metadata:local_agentdetect_signals:ipaddress'] = 'The client IP address at the time of detection.';
$string['privacy:metadata:local_agentdetect_signals:sessionid'] = 'A unique identifier for the detection session.';
$string['privacy:metadata:local_agentdetect_signals:signaldata'] = 'JSON-encoded detailed signal data collected from the browser.';
$string['privacy:metadata:local_agentdetect_signals:signaltype'] = 'The type of detection signal recorded.';
$string['privacy:metadata:local_agentdetect_signals:timecreated'] = 'Timestamp when the signals were collected.';
$string['privacy:metadata:local_agentdetect_signals:useragent'] = 'The browser user agent string.';
$string['privacy:metadata:local_agentdetect_signals:userid'] = 'The ID of the user whose session is being analyzed.';
$string['privacy:metadata:local_agentdetect_signals:verdict'] = 'The detection verdict string.';
$string['report:allsessions'] = 'All sessions';
$string['report:allusers'] = 'All users';
$string['report:avgscore'] = 'Avg score';
$string['report:clearfilters'] = 'Clear filters';
$string['report:downloadjson'] = 'Download JSON';
$string['report:filter'] = 'Filter';
$string['report:filtersignals'] = 'Filter signals';
$string['report:maxscore'] = 'Max score';
$string['report:noflags'] = 'No users flagged yet.';
$string['report:nosignals'] = 'No signals recorded yet.';
$string['report:signalsfor'] = 'Signals for {$a}';
$string['report:storedsignals'] = 'Stored detection signals';
$string['report:userflags'] = 'User flags';
$string['report:usersummary'] = 'User summary: ';
$string['report:viewsignals'] = 'View signals';
$string['settings:collectinteraction'] = 'Collect interaction data';
$string['settings:collectinteraction_desc'] = 'Track mouse movements, click patterns, and keystroke timing for behavioral analysis.';
$string['settings:debug'] = 'Debug mode';
$string['settings:debug_desc'] = 'Enable debug logging in browser console.';
$string['settings:enabled'] = 'Enable agent detection';
$string['settings:enabled_desc'] = 'When enabled, the plugin will collect browser signals and flag potential automated sessions.';
$string['settings:minreportscore'] = 'Minimum report score';
$string['settings:minreportscore_desc'] = 'Only report signals with combined score at or above this value (0-100).';
$string['settings:pagetypes'] = 'Page types to monitor';
$string['settings:pagetypes_desc'] = 'Comma-separated list of page types to enable detection on. Use * for wildcard. Leave empty to monitor all pages. Examples: mod-assign-*, mod-quiz-*, mod-forum-*';
$string['settings:reportinterval'] = 'Report interval (ms)';
$string['settings:reportinterval_desc'] = 'How often to send detection reports to the server (in milliseconds).';
$string['settings:threshold'] = 'Detection threshold';
$string['settings:threshold_desc'] = 'Agent probability score (0-100) above which a session is flagged. Lower values are more sensitive.';
$string['signal:extension'] = 'Known automation extension detected';
$string['signal:fingerprint'] = 'Suspicious browser fingerprint';
$string['signal:headless'] = 'Headless browser indicators';
$string['signal:interaction'] = 'Anomalous interaction patterns';
$string['signal:webdriver'] = 'WebDriver flag detected';
$string['testpage'] = 'Agent Detection Test';
$string['verdict:highconfidenceagent'] = 'High confidence agent';
$string['verdict:likelyhuman'] = 'Likely human';
$string['verdict:lowsuspicion'] = 'Low suspicion';
$string['verdict:probableagent'] = 'Probable agent';
$string['verdict:suspicious'] = 'Suspicious';
