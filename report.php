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
 * Report page to view stored agent detection signals.
 *
 * @package    local_agentdetect
 * @copyright  2024 Your Institution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

// Get filter parameters.
$userid = optional_param('userid', 0, PARAM_INT);
$sessionid = optional_param('sessionid', '', PARAM_ALPHANUMEXT);
$download = optional_param('download', '', PARAM_ALPHA);

// Handle JSON download.
if ($download === 'json') {
    // Build query based on filters.
    $where = [];
    $params = [];

    if ($userid) {
        $where[] = 's.userid = :userid';
        $params['userid'] = $userid;
    }
    if ($sessionid) {
        $where[] = 's.sessionid = :sessionid';
        $params['sessionid'] = $sessionid;
    }

    $wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $signals = $DB->get_records_sql(
        "SELECT s.*, u.firstname, u.lastname, u.email
           FROM {local_agentdetect_signals} s
           JOIN {user} u ON u.id = s.userid
           {$wheresql}
          ORDER BY s.timecreated DESC",
        $params
    );

    // Build JSON output.
    $output = [];
    foreach ($signals as $signal) {
        $data = json_decode($signal->signaldata);

        $output[] = [
            'time' => userdate($signal->timecreated, '%Y-%m-%d %H:%M:%S'),
            'timestamp' => $signal->timecreated,
            'user_id' => $signal->userid,
            'user_name' => fullname($signal),
            'email' => $signal->email,
            'session_id' => $signal->sessionid,
            'signal_type' => $signal->signaltype,
            'page_url' => $data->pageUrl ?? null,
            'page_title' => $data->pageTitle ?? null,
            'fp_score' => $signal->fingerprintscore,
            'int_score' => $signal->interactionscore,
            'inj_score' => $data->injection->score ?? null,
            'combined_score' => $signal->combinedscore,
            'verdict' => $signal->verdict,
            'event_counts' => $data->interaction->eventCounts ?? null,
            'anomalies' => $data->interaction->anomalies ?? [],
            'injection_signals' => $data->injection->signals ?? [],
            'comet_detected' => $data->comet->detected ?? false,
            'comet_score' => $data->comet->score ?? null,
            'comet_signals' => $data->comet->signals ?? [],
            'detected_agent' => $data->detectedAgent ?? null,
            'ip_address' => $signal->ipaddress,
            'user_agent' => $signal->useragent,
        ];
    }

    // Output JSON.
    $filename = 'agentdetect_signals_' . date('Y-m-d_His') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($output, JSON_PRETTY_PRINT);
    exit;
}

$PAGE->set_url(new moodle_url('/local/agentdetect/report.php', ['userid' => $userid, 'sessionid' => $sessionid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Agent Detection Report');
$PAGE->set_heading('Agent Detection Report');

echo $OUTPUT->header();

// Get all users with signals for the dropdown.
$usersWithSignals = $DB->get_records_sql(
    "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
            MAX(s.combinedscore) as maxscore,
            COUNT(s.id) as signalcount
       FROM {local_agentdetect_signals} s
       JOIN {user} u ON u.id = s.userid
      GROUP BY u.id, u.firstname, u.lastname, u.email
      ORDER BY maxscore DESC, u.lastname, u.firstname"
);

// Filter form.
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', 'Filter Signals', ['class' => 'card-title']);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring(), 'class' => 'form-inline']);

// User dropdown.
echo html_writer::start_div('form-group mr-3 mb-2');
echo html_writer::label('User: ', 'userid', true, ['class' => 'mr-2']);
$useroptions = [0 => 'All Users'];
foreach ($usersWithSignals as $u) {
    $scoreLabel = $u->maxscore >= 70 ? ' ⚠️' : ($u->maxscore >= 40 ? ' ⚡' : '');
    $useroptions[$u->id] = fullname($u) . " ({$u->signalcount} signals, max: {$u->maxscore}){$scoreLabel}";
}
echo html_writer::select($useroptions, 'userid', $userid, null, ['class' => 'form-control', 'id' => 'userid']);
echo html_writer::end_div();

// Session filter (only show if user selected).
if ($userid) {
    $sessions = $DB->get_records_sql(
        "SELECT DISTINCT sessionid, MIN(timecreated) as firstseen, MAX(combinedscore) as maxscore
           FROM {local_agentdetect_signals}
          WHERE userid = :userid
          GROUP BY sessionid
          ORDER BY firstseen DESC",
        ['userid' => $userid]
    );

    echo html_writer::start_div('form-group mr-3 mb-2');
    echo html_writer::label('Session: ', 'sessionid', true, ['class' => 'mr-2']);
    $sessionoptions = ['' => 'All Sessions'];
    foreach ($sessions as $s) {
        $sessionoptions[$s->sessionid] = $s->sessionid . ' (max: ' . ($s->maxscore ?? '?') . ')';
    }
    echo html_writer::select($sessionoptions, 'sessionid', $sessionid, null, ['class' => 'form-control', 'id' => 'sessionid']);
    echo html_writer::end_div();
}

echo html_writer::tag('button', 'Filter', ['type' => 'submit', 'class' => 'btn btn-primary mb-2 mr-2']);
if ($userid || $sessionid) {
    echo html_writer::link(
        new moodle_url('/local/agentdetect/report.php'),
        'Clear Filters',
        ['class' => 'btn btn-secondary mb-2 mr-2']
    );
}

// JSON download button.
$downloadurl = new moodle_url('/local/agentdetect/report.php', [
    'userid' => $userid,
    'sessionid' => $sessionid,
    'download' => 'json',
]);
echo html_writer::link(
    $downloadurl,
    '📥 Download JSON',
    ['class' => 'btn btn-outline-success mb-2']
);

echo html_writer::end_tag('form');

echo html_writer::end_div();
echo html_writer::end_div();

// Build query based on filters.
$where = [];
$params = [];

if ($userid) {
    $where[] = 's.userid = :userid';
    $params['userid'] = $userid;
}
if ($sessionid) {
    $where[] = 's.sessionid = :sessionid';
    $params['sessionid'] = $sessionid;
}

$wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$limit = ($userid || $sessionid) ? 200 : 50;

// Section header.
if ($userid) {
    $selectedUser = $DB->get_record('user', ['id' => $userid]);
    echo html_writer::tag('h3', 'Signals for ' . fullname($selectedUser));
} else {
    echo html_writer::tag('h3', 'Stored Detection Signals');
}

// Get signals.
$signals = $DB->get_records_sql(
    "SELECT s.*, u.firstname, u.lastname, u.email
       FROM {local_agentdetect_signals} s
       JOIN {user} u ON u.id = s.userid
       {$wheresql}
      ORDER BY s.timecreated DESC
      LIMIT {$limit}",
    $params
);

if (empty($signals)) {
    echo html_writer::div('No signals recorded yet.', 'alert alert-info');
} else {
    // Summary stats.
    $countparams = $params;
    $countwhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $totalcount = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_agentdetect_signals} s {$countwhere}",
        $countparams
    );
    echo html_writer::tag('p', "Showing latest {$limit} of {$totalcount} total signals.");

    // If viewing a user, show summary stats.
    if ($userid) {
        $stats = $DB->get_record_sql(
            "SELECT COUNT(*) as total,
                    MAX(combinedscore) as maxscore,
                    AVG(combinedscore) as avgscore,
                    SUM(CASE WHEN verdict = 'HIGH_CONFIDENCE_AGENT' THEN 1 ELSE 0 END) as high_count,
                    SUM(CASE WHEN verdict = 'PROBABLE_AGENT' THEN 1 ELSE 0 END) as probable_count,
                    SUM(CASE WHEN verdict = 'SUSPICIOUS' THEN 1 ELSE 0 END) as suspicious_count,
                    SUM(CASE WHEN verdict = 'LIKELY_HUMAN' THEN 1 ELSE 0 END) as human_count
               FROM {local_agentdetect_signals}
              WHERE userid = :userid",
            ['userid' => $userid]
        );

        echo html_writer::start_div('alert alert-secondary');
        echo html_writer::tag('strong', 'User Summary: ');
        echo "Max Score: <strong>{$stats->maxscore}</strong> | ";
        echo "Avg Score: <strong>" . round($stats->avgscore, 1) . "</strong> | ";
        echo "HIGH_CONFIDENCE: <span class='badge badge-danger'>{$stats->high_count}</span> | ";
        echo "PROBABLE: <span class='badge badge-warning'>{$stats->probable_count}</span> | ";
        echo "SUSPICIOUS: <span class='badge badge-warning'>{$stats->suspicious_count}</span> | ";
        echo "LIKELY_HUMAN: <span class='badge badge-success'>{$stats->human_count}</span>";
        echo html_writer::end_div();
    }

    // Signals table.
    $table = new html_table();
    $table->head = ['Time', 'User', 'Page', 'Type', 'FP', 'Int', 'Inj', 'Combined', 'Verdict', 'Details'];
    $table->attributes['class'] = 'table table-striped table-sm';

    foreach ($signals as $signal) {
        $time = userdate($signal->timecreated, '%Y-%m-%d %H:%M:%S');

        // User link.
        $userlink = html_writer::link(
            new moodle_url('/local/agentdetect/report.php', ['userid' => $signal->userid]),
            fullname($signal),
            ['title' => $signal->email]
        );

        // Get page URL from signal data.
        $data = json_decode($signal->signaldata);
        $pageurl = $data->pageUrl ?? null;
        $pagetitle = $data->pageTitle ?? null;

        // Page link - show title or shortened URL.
        if ($pageurl) {
            $displaytext = $pagetitle ? $pagetitle : basename(parse_url($pageurl, PHP_URL_PATH));
            if (strlen($displaytext) > 30) {
                $displaytext = substr($displaytext, 0, 27) . '...';
            }
            $pagelink = html_writer::link(
                $pageurl,
                $displaytext,
                ['title' => $pageurl, 'target' => '_blank']
            );
        } else {
            $pagelink = html_writer::tag('span', '-', ['class' => 'text-muted']);
        }

        // Color code scores.
        $fpscore = $signal->fingerprintscore ?? '-';
        $intscore = $signal->interactionscore ?? '-';
        $combined = $signal->combinedscore ?? '-';

        // Get injection score from signal data (data already decoded above).
        $injscore = '-';
        if ($data && isset($data->injection->score)) {
            $injscore = $data->injection->score;
        }

        if (is_numeric($combined)) {
            if ($combined >= 70) {
                $combined = html_writer::tag('span', $combined, ['class' => 'badge badge-danger']);
            } else if ($combined >= 40) {
                $combined = html_writer::tag('span', $combined, ['class' => 'badge badge-warning']);
            } else {
                $combined = html_writer::tag('span', $combined, ['class' => 'badge badge-success']);
            }
        }

        // Verdict badge.
        $verdict = $signal->verdict ?? '-';
        if ($verdict === 'HIGH_CONFIDENCE_AGENT') {
            $verdict = html_writer::tag('span', 'HIGH', ['class' => 'badge badge-danger', 'title' => 'HIGH_CONFIDENCE_AGENT']);
        } else if ($verdict === 'PROBABLE_AGENT') {
            $verdict = html_writer::tag('span', 'PROBABLE', ['class' => 'badge badge-warning', 'title' => 'PROBABLE_AGENT']);
        } else if ($verdict === 'SUSPICIOUS') {
            $verdict = html_writer::tag('span', 'SUSPICIOUS', ['class' => 'badge badge-warning']);
        } else if ($verdict === 'LOW_SUSPICION') {
            $verdict = html_writer::tag('span', 'LOW', ['class' => 'badge badge-info', 'title' => 'LOW_SUSPICION']);
        } else if ($verdict === 'LIKELY_HUMAN') {
            $verdict = html_writer::tag('span', 'HUMAN', ['class' => 'badge badge-success', 'title' => 'LIKELY_HUMAN']);
        }

        // Comet agent badge.
        if (isset($data->detectedAgent) && $data->detectedAgent === 'comet_agentic') {
            $verdict .= ' ' . html_writer::tag('span', 'COMET', ['class' => 'badge badge-dark']);
        }

        // Details - decode and show anomalies.
        $detailshtml = '';
        if ($data) {
            $details = [];

            // Interaction anomalies.
            if (isset($data->interaction->anomalies) && !empty($data->interaction->anomalies)) {
                foreach ($data->interaction->anomalies as $a) {
                    $details[] = '<span class="text-danger">' . $a->name . '</span> (' . round($a->value, 2) . ') w:' . $a->weight;
                }
            }

            // Injection signals.
            if (isset($data->injection->signals) && !empty($data->injection->signals)) {
                foreach ($data->injection->signals as $s) {
                    $details[] = '<span class="text-info">[INJ] ' . $s->name . '</span> (' . $s->count . ') w:' . $s->maxWeight;
                }
            }

            // Comet agentic signals.
            if (isset($data->comet) && !empty($data->comet->detected)) {
                $details[] = '<span class="text-danger font-weight-bold">[COMET AGENT] Score: ' .
                    ($data->comet->score ?? '?') . '</span>';
                if (isset($data->comet->signals) && !empty($data->comet->signals)) {
                    foreach ($data->comet->signals as $cs) {
                        $weight = $cs->weight ?? $cs->maxWeight ?? '?';
                        $details[] = '<span class="text-danger">[COMET] ' .
                            htmlspecialchars($cs->name) . '</span> w:' . $weight;
                    }
                }
            }

            // Event counts (for context).
            if (isset($data->interaction->eventCounts)) {
                $ec = $data->interaction->eventCounts;
                $details[] = "<span class='text-muted'>events: m:{$ec->mouseMoves} c:{$ec->clicks} k:{$ec->keystrokes}</span>";
            }

            $detailshtml = html_writer::tag('small', implode('<br>', $details));
        } else {
            $detailshtml = html_writer::tag('small', '-', ['class' => 'text-muted']);
        }

        $table->data[] = [
            $time,
            $userlink,
            $pagelink,
            $signal->signaltype,
            $fpscore,
            $intscore,
            $injscore,
            $combined,
            $verdict,
            $detailshtml,
        ];
    }

    echo html_writer::table($table);
}

// Flags section (only show on main view).
if (!$userid) {
    echo html_writer::tag('h3', 'User Flags', ['style' => 'margin-top: 30px;']);

    $flags = $DB->get_records_sql(
        "SELECT f.*, u.firstname, u.lastname, u.email
           FROM {local_agentdetect_flags} f
           JOIN {user} u ON u.id = f.userid
          ORDER BY f.maxscore DESC, f.timemodified DESC
          LIMIT 50"
    );

    if (empty($flags)) {
        echo html_writer::div('No users flagged yet.', 'alert alert-info');
    } else {
        $flagtable = new html_table();
        $flagtable->head = ['User', 'Flag Type', 'Max Score', 'Detection Count', 'Last Updated', 'Actions'];
        $flagtable->attributes['class'] = 'table table-striped table-sm';

        foreach ($flags as $flag) {
            $userlink = html_writer::link(
                new moodle_url('/local/agentdetect/report.php', ['userid' => $flag->userid]),
                fullname($flag) . ' (' . $flag->email . ')'
            );
            $time = userdate($flag->timemodified, '%Y-%m-%d %H:%M:%S');

            $flagtype = $flag->flagtype;
            if ($flagtype === 'agent_suspected' || $flagtype === 'agent_confirmed') {
                $flagtype = html_writer::tag('span', $flagtype, ['class' => 'badge badge-danger']);
            } else if ($flagtype === 'low_suspicion') {
                $flagtype = html_writer::tag('span', $flagtype, ['class' => 'badge badge-warning']);
            } else {
                $flagtype = html_writer::tag('span', $flagtype, ['class' => 'badge badge-secondary']);
            }

            $actions = html_writer::link(
                new moodle_url('/local/agentdetect/report.php', ['userid' => $flag->userid]),
                'View Signals',
                ['class' => 'btn btn-sm btn-outline-primary']
            );

            $flagtable->data[] = [
                $userlink,
                $flagtype,
                $flag->maxscore,
                $flag->detectioncount,
                $time,
                $actions,
            ];
        }

        echo html_writer::table($flagtable);
    }
}

echo $OUTPUT->footer();
