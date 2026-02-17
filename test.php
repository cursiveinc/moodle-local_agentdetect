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
 * Test page for agent detection - shows real-time detection results.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/agentdetect:configure', context_system::instance());

// Only accessible in debug mode.
if (!debugging('', DEBUG_DEVELOPER)) {
    throw new moodle_exception('error:debugonly', 'local_agentdetect');
}

$PAGE->set_url(new moodle_url('/local/agentdetect/test.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('testpage', 'local_agentdetect'));
$PAGE->set_heading(get_string('testpage', 'local_agentdetect'));

echo $OUTPUT->header();

echo html_writer::tag('h3', get_string('testpage', 'local_agentdetect'));
echo html_writer::tag('p', get_string('testpage:description', 'local_agentdetect'));

// Results containers.
echo html_writer::start_div('', ['id' => 'agentdetect-results', 'style' => 'margin-top: 20px;']);

echo html_writer::tag('h4', get_string('testpage:fingerprint', 'local_agentdetect'));
echo html_writer::div(get_string('testpage:collecting', 'local_agentdetect'), '', ['id' => 'fingerprint-results',
    'style' => 'background: #f5f5f5; padding: 15px; margin-bottom: 20px; font-family: monospace; white-space: pre-wrap;']);

echo html_writer::tag('h4', get_string('testpage:interaction', 'local_agentdetect'));
echo html_writer::div(get_string('testpage:monitoring', 'local_agentdetect'), '', ['id' => 'interaction-results',
    'style' => 'background: #f5f5f5; padding: 15px; margin-bottom: 20px; font-family: monospace; white-space: pre-wrap;']);

echo html_writer::tag('h4', get_string('testpage:combined', 'local_agentdetect'));
echo html_writer::div(get_string('testpage:calculating', 'local_agentdetect'), '', ['id' => 'combined-score',
    'style' => 'background: #e0e0e0; padding: 20px; font-size: 24px; text-align: center;']);

echo html_writer::end_div();

// Test interaction area.
echo html_writer::tag('h4', get_string('testpage:interactionarea', 'local_agentdetect'), ['style' => 'margin-top: 30px;']);
echo html_writer::start_div('', ['style' => 'background: #fff; border: 2px solid #ccc; padding: 20px;']);
echo html_writer::tag('p', get_string('testpage:typesomething', 'local_agentdetect'));
echo html_writer::empty_tag('input', ['type' => 'text', 'id' => 'test-input',
    'style' => 'width: 100%; padding: 10px; font-size: 16px;',
    'placeholder' => get_string('testpage:inputplaceholder', 'local_agentdetect')]);
echo html_writer::tag('p', get_string('testpage:clickbuttons', 'local_agentdetect'), ['style' => 'margin-top: 15px;']);
echo html_writer::tag('button', get_string('testpage:button', 'local_agentdetect', 1),
    ['class' => 'btn btn-primary', 'style' => 'margin-right: 10px;']);
echo html_writer::tag('button', get_string('testpage:button', 'local_agentdetect', 2),
    ['class' => 'btn btn-secondary', 'style' => 'margin-right: 10px;']);
echo html_writer::tag('button', get_string('testpage:button', 'local_agentdetect', 3),
    ['class' => 'btn btn-info']);
echo html_writer::tag(
    'div',
    get_string('testpage:scrollarea', 'local_agentdetect'),
    ['style' => 'height: 200px; overflow-y: scroll; margin-top: 15px; background: #fafafa; padding: 10px;']
);
echo html_writer::end_div();

// Inline JavaScript to run detection and display results.
$PAGE->requires->js_amd_inline("
require(['local_agentdetect/fingerprint', 'local_agentdetect/interaction'], function(Fingerprint, Interaction) {

    // Display fingerprint results.
    Fingerprint.collect().then(function(results) {
        var el = document.getElementById('fingerprint-results');
        el.textContent = JSON.stringify(results, null, 2);

        // Color code based on score.
        if (results.score > 50) {
            el.style.background = '#ffcccc';
        } else if (results.score > 20) {
            el.style.background = '#ffffcc';
        } else {
            el.style.background = '#ccffcc';
        }
    });

    // Start interaction monitoring.
    Interaction.startMonitoring();

    // Update interaction results periodically.
    function updateInteraction() {
        var results = Interaction.analyze();
        var el = document.getElementById('interaction-results');
        el.textContent = JSON.stringify(results, null, 2);

        // Color code based on score.
        if (results.score > 50) {
            el.style.background = '#ffcccc';
        } else if (results.score > 20) {
            el.style.background = '#ffffcc';
        } else {
            el.style.background = '#ccffcc';
        }

        // Update combined score.
        Fingerprint.collect().then(function(fpResults) {
            var combined = Math.round((fpResults.score + results.score) / 2);
            var scoreEl = document.getElementById('combined-score');
            scoreEl.textContent = 'Combined Score: ' + combined + '/100';

            if (combined > 50) {
                scoreEl.style.background = '#ff6666';
                scoreEl.style.color = 'white';
            } else if (combined > 20) {
                scoreEl.style.background = '#ffff66';
                scoreEl.style.color = 'black';
            } else {
                scoreEl.style.background = '#66ff66';
                scoreEl.style.color = 'black';
            }
        });
    }

    // Update every 2 seconds.
    setInterval(updateInteraction, 2000);

    // Initial update after short delay.
    setTimeout(updateInteraction, 1000);

    console.log('Agent detection test loaded. Fingerprint and Interaction modules active.');
});
");

echo $OUTPUT->footer();
