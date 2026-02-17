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
 * Course-level agent detection report for teachers.
 *
 * Shows flagged students in the course (summary view) or
 * per-session signal summaries for a specific student (detail view).
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/agentdetect:viewreports', $context);

$pageurl = new moodle_url('/local/agentdetect/coursereport.php', ['courseid' => $courseid]);
if ($userid) {
    $pageurl->param('userid', $userid);
}

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('coursereport:title', 'local_agentdetect'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursereport:title', 'local_agentdetect'));

if ($userid) {
    // Student detail view — per-session signal summaries.
    display_student_signals($courseid, $userid, $context);
} else {
    // Summary view — flagged students in this course.
    display_flagged_students($courseid, $context);
}

echo $OUTPUT->footer();

/**
 * Display flagged students in the course.
 *
 * @param int $courseid The course ID.
 * @param context_course $context The course context.
 */
function display_flagged_students(int $courseid, context_course $context): void {
    global $DB;

    // Get all context IDs for this course and its child modules.
    $contextids = get_course_context_ids($context);

    if (empty($contextids)) {
        echo html_writer::div(
            get_string('coursereport:noflags', 'local_agentdetect'),
            'alert alert-info'
        );
        return;
    }

    // Get enrolled users who have flags in these contexts.
    [$ctxinsql, $ctxparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

    $sql = "SELECT f.userid, u.firstname, u.lastname, u.email,
                   MAX(f.maxscore) AS maxscore,
                   MAX(f.detectioncount) AS detectioncount,
                   MAX(f.timemodified) AS lastdetected,
                   f.flagtype
              FROM {local_agentdetect_flags} f
              JOIN {user} u ON u.id = f.userid
             WHERE (f.contextid {$ctxinsql} OR f.contextid IS NULL)
               AND f.flagtype != 'cleared'
          GROUP BY f.userid, u.firstname, u.lastname, u.email, f.flagtype
          ORDER BY maxscore DESC, lastdetected DESC";

    $flags = $DB->get_records_sql($sql, $ctxparams);

    if (empty($flags)) {
        echo html_writer::div(
            get_string('coursereport:noflags', 'local_agentdetect'),
            'alert alert-info'
        );
        return;
    }

    // Filter to only enrolled users.
    $enrolledusers = get_enrolled_users($context, '', 0, 'u.id');
    $enrolledids = array_keys($enrolledusers);

    $table = new html_table();
    $table->head = [
        get_string('user'),
        get_string('coursereport:flagtype', 'local_agentdetect'),
        get_string('coursereport:maxscore', 'local_agentdetect'),
        get_string('coursereport:detectioncount', 'local_agentdetect'),
        get_string('coursereport:lastdetected', 'local_agentdetect'),
        '',
    ];
    $table->attributes['class'] = 'table table-striped table-sm';

    $hasrows = false;
    foreach ($flags as $flag) {
        if (!in_array((int) $flag->userid, $enrolledids)) {
            continue;
        }
        $hasrows = true;

        $userlink = html_writer::link(
            new moodle_url('/user/view.php', ['id' => $flag->userid, 'course' => $courseid]),
            fullname($flag)
        );

        $flagbadge = format_flag_badge($flag->flagtype);

        $detailurl = new moodle_url('/local/agentdetect/coursereport.php', [
            'courseid' => $courseid,
            'userid' => $flag->userid,
        ]);
        $actions = html_writer::link(
            $detailurl,
            get_string('coursereport:viewdetails', 'local_agentdetect'),
            ['class' => 'btn btn-sm btn-outline-primary']
        );

        $table->data[] = [
            $userlink,
            $flagbadge,
            $flag->maxscore,
            $flag->detectioncount,
            userdate($flag->lastdetected, '%Y-%m-%d %H:%M'),
            $actions,
        ];
    }

    if ($hasrows) {
        echo html_writer::table($table);
    } else {
        echo html_writer::div(
            get_string('coursereport:noflags', 'local_agentdetect'),
            'alert alert-info'
        );
    }
}

/**
 * Display signal sessions for a specific student.
 *
 * @param int $courseid The course ID.
 * @param int $userid The user ID.
 * @param context_course $context The course context.
 */
function display_student_signals(int $courseid, int $userid, context_course $context): void {
    global $DB, $OUTPUT;

    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

    // Breadcrumb back to summary.
    $summaryurl = new moodle_url('/local/agentdetect/coursereport.php', ['courseid' => $courseid]);
    echo html_writer::link(
        $summaryurl,
        '&laquo; ' . get_string('coursereport:flaggedstudents', 'local_agentdetect'),
        ['class' => 'mb-3 d-block']
    );

    echo $OUTPUT->heading(get_string('coursereport:studentsignals', 'local_agentdetect', fullname($user)), 3);

    // Caveat notice.
    echo html_writer::div(
        get_string('coursereport:caveat', 'local_agentdetect'),
        'alert alert-warning'
    );

    // Get all context IDs for this course.
    $contextids = get_course_context_ids($context);

    if (empty($contextids)) {
        echo html_writer::div(
            get_string('coursereport:nosignals', 'local_agentdetect'),
            'alert alert-info'
        );
        return;
    }

    [$ctxinsql, $ctxparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
    $ctxparams['userid'] = $userid;

    // Get session summaries — grouped by sessionid, sorted by highest score first.
    $sql = "SELECT s.sessionid,
                   MIN(s.timecreated) AS firstseen,
                   MAX(s.combinedscore) AS maxscore,
                   MAX(s.verdict) AS verdict,
                   COUNT(s.id) AS signalcount
              FROM {local_agentdetect_signals} s
             WHERE s.userid = :userid
               AND s.contextid {$ctxinsql}
          GROUP BY s.sessionid
          ORDER BY maxscore DESC, firstseen DESC";

    $sessions = $DB->get_records_sql($sql, $ctxparams, 0, 50);

    if (empty($sessions)) {
        echo html_writer::div(
            get_string('coursereport:nosignals', 'local_agentdetect'),
            'alert alert-info'
        );
        return;
    }

    // Pre-load combined signal records for all sessions so we can filter and build the roll-up.
    $sessioncards = [];
    $overallmaxscore = 0;
    $overallmaxverdict = 'LIKELY_HUMAN';
    $totalsessions = 0;
    $sessionswithsignals = 0;
    $allsignalnames = [];

    // Verdict severity ordering for roll-up "worst" verdict.
    $verdictseverity = [
        'LIKELY_HUMAN' => 0,
        'LOW_SUSPICION' => 1,
        'SUSPICIOUS' => 2,
        'PROBABLE_AGENT' => 3,
        'HIGH_CONFIDENCE_AGENT' => 4,
    ];

    foreach ($sessions as $session) {
        $totalsessions++;

        // Load the highest-scoring combined signal record for this session.
        $signalrecord = $DB->get_record_sql(
            "SELECT s.signaldata, s.combinedscore, s.verdict
               FROM {local_agentdetect_signals} s
              WHERE s.sessionid = :sessionid
                AND s.userid = :userid
                AND s.signaltype = 'combined'
           ORDER BY s.combinedscore DESC",
            ['sessionid' => $session->sessionid, 'userid' => $userid],
            IGNORE_MULTIPLE
        );

        $explanations = [];
        if ($signalrecord && $signalrecord->signaldata) {
            $data = json_decode($signalrecord->signaldata);
            $explanations = build_signal_explanations($data);
        }

        // Skip sessions with no explainable signal data.
        if (empty($explanations)) {
            continue;
        }

        $sessionswithsignals++;

        // Track roll-up metrics.
        $score = (int) $session->maxscore;
        if ($score > $overallmaxscore) {
            $overallmaxscore = $score;
        }
        $verdict = $session->verdict ?? 'LIKELY_HUMAN';
        $currentseverity = $verdictseverity[$verdict] ?? 0;
        $maxseverity = $verdictseverity[$overallmaxverdict] ?? 0;
        if ($currentseverity > $maxseverity) {
            $overallmaxverdict = $verdict;
        }

        // Collect unique signal names for the roll-up.
        foreach ($explanations as $explanation) {
            $allsignalnames[] = $explanation;
        }

        $sessioncards[] = [
            'session' => $session,
            'explanations' => $explanations,
        ];
    }

    if (empty($sessioncards)) {
        echo html_writer::div(
            get_string('coursereport:nosignals', 'local_agentdetect'),
            'alert alert-info'
        );
        return;
    }

    // Roll-up summary card at top.
    $scorebadge = format_score_badge($overallmaxscore);
    $verdictbadge = format_verdict_badge($overallmaxverdict);

    echo html_writer::start_div('card mb-4 border-primary');
    echo html_writer::start_div('card-header bg-primary text-white');
    echo html_writer::tag('strong', get_string('coursereport:summary', 'local_agentdetect'));
    echo html_writer::end_div();
    echo html_writer::start_div('card-body');

    // Metrics row.
    echo html_writer::start_div('d-flex flex-wrap mb-3');

    echo html_writer::start_div('mr-4 mb-2');
    echo html_writer::tag(
        'small',
        get_string('coursereport:highestscore', 'local_agentdetect'),
        ['class' => 'd-block text-muted']
    );
    echo html_writer::tag('span', $scorebadge, ['class' => 'h5']);
    echo html_writer::end_div();

    echo html_writer::start_div('mr-4 mb-2');
    echo html_writer::tag(
        'small',
        get_string('coursereport:highestverdict', 'local_agentdetect'),
        ['class' => 'd-block text-muted']
    );
    echo html_writer::tag('span', $verdictbadge, ['class' => 'h5']);
    echo html_writer::end_div();

    echo html_writer::start_div('mr-4 mb-2');
    echo html_writer::tag(
        'small',
        get_string('coursereport:sessioncount', 'local_agentdetect'),
        ['class' => 'd-block text-muted']
    );
    echo html_writer::tag(
        'span',
        $sessionswithsignals . ' / ' . $totalsessions,
        ['class' => 'h5']
    );
    echo html_writer::end_div();

    echo html_writer::end_div(); // Metrics row.

    echo html_writer::end_div(); // Card body.
    echo html_writer::end_div(); // Card.

    // Per-session cards (filtered, highest score first).
    foreach ($sessioncards as $card) {
        $session = $card['session'];
        $explanations = $card['explanations'];

        $scorebadge = format_score_badge($session->maxscore);
        $verdictbadge = format_verdict_badge($session->verdict);
        $shortsessionid = substr($session->sessionid, 0, 12) . '...';

        // Card header.
        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-header d-flex justify-content-between align-items-center');
        echo html_writer::tag('span', userdate($session->firstseen, '%Y-%m-%d %H:%M') . ' &mdash; ' . $verdictbadge
            . ' ' . $scorebadge);
        echo html_writer::tag('small', $shortsessionid, ['class' => 'text-muted', 'title' => $session->sessionid]);
        echo html_writer::end_div();

        // Card body — signal explanations.
        echo html_writer::start_div('card-body');

        echo html_writer::tag(
            'p',
            get_string('coursereport:whyflagged', 'local_agentdetect'),
            ['class' => 'font-weight-bold mb-2']
        );
        echo html_writer::start_tag('ul', ['class' => 'mb-0']);
        foreach ($explanations as $explanation) {
            echo html_writer::tag('li', $explanation);
        }
        echo html_writer::end_tag('ul');

        echo html_writer::end_div(); // Card body.
        echo html_writer::end_div(); // Card.
    }

    // If user also has viewsignals, show link to admin report.
    if (has_capability('local/agentdetect:viewsignals', context_system::instance())) {
        $adminurl = new moodle_url('/local/agentdetect/report.php', ['userid' => $userid]);
        $adminlink = html_writer::link(
            $adminurl,
            get_string('coursereport:viewadminreport', 'local_agentdetect'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        );
        echo html_writer::div($adminlink, 'mt-3');
    }
}

/**
 * Get all context IDs for a course and its child modules.
 *
 * @param context_course $context The course context.
 * @return array Array of context IDs.
 */
function get_course_context_ids(context_course $context): array {
    global $DB;

    $path = $context->path;
    $like = $DB->sql_like('path', ':path');
    $params = ['path' => $path . '/%', 'selfid' => $context->id];

    return $DB->get_fieldset_select('context', 'id', "{$like} OR id = :selfid", $params);
}

/**
 * Format a flag type as a Bootstrap badge.
 *
 * @param string $flagtype The flag type.
 * @return string HTML badge.
 */
function format_flag_badge(string $flagtype): string {
    if ($flagtype === 'agent_suspected' || $flagtype === 'agent_confirmed') {
        return html_writer::tag('span', $flagtype, ['class' => 'badge badge-danger']);
    } else if ($flagtype === 'low_suspicion') {
        return html_writer::tag('span', $flagtype, ['class' => 'badge badge-warning']);
    }
    return html_writer::tag('span', $flagtype, ['class' => 'badge badge-secondary']);
}

/**
 * Format a score as a coloured Bootstrap badge.
 *
 * @param int|string $score The score value.
 * @return string HTML badge.
 */
function format_score_badge($score): string {
    if (!is_numeric($score)) {
        return (string) $score;
    }
    if ($score >= 70) {
        return html_writer::tag('span', $score, ['class' => 'badge badge-danger']);
    } else if ($score >= 40) {
        return html_writer::tag('span', $score, ['class' => 'badge badge-warning']);
    }
    return html_writer::tag('span', $score, ['class' => 'badge badge-success']);
}

/**
 * Format a verdict as a Bootstrap badge.
 *
 * @param string|null $verdict The verdict string.
 * @return string HTML badge.
 */
function format_verdict_badge(?string $verdict): string {
    if ($verdict === null) {
        return '-';
    }
    switch ($verdict) {
        case 'HIGH_CONFIDENCE_AGENT':
            return html_writer::tag('span', 'HIGH', ['class' => 'badge badge-danger', 'title' => $verdict]);
        case 'PROBABLE_AGENT':
            return html_writer::tag('span', 'PROBABLE', ['class' => 'badge badge-warning', 'title' => $verdict]);
        case 'SUSPICIOUS':
            return html_writer::tag('span', 'SUSPICIOUS', ['class' => 'badge badge-warning']);
        case 'LOW_SUSPICION':
            return html_writer::tag('span', 'LOW', ['class' => 'badge badge-info', 'title' => $verdict]);
        case 'LIKELY_HUMAN':
            return html_writer::tag('span', 'HUMAN', ['class' => 'badge badge-success', 'title' => $verdict]);
        default:
            return html_writer::tag('span', $verdict, ['class' => 'badge badge-secondary']);
    }
}

/**
 * Build plain-language explanations from signal data.
 *
 * Extracts the most significant signals from the JSON signal data and
 * returns teacher-friendly descriptions of what was detected.
 *
 * @param object $data Decoded JSON signal data.
 * @return array Array of explanation strings (HTML safe).
 */
function build_signal_explanations(object $data): array {
    $explanations = [];

    // Collect all anomaly signals with their weights.
    $signals = [];

    // Interaction anomalies.
    if (isset($data->interaction->anomalies) && is_array($data->interaction->anomalies)) {
        foreach ($data->interaction->anomalies as $a) {
            $signals[] = (object) [
                'name' => $a->name,
                'value' => $a->value ?? null,
                'weight' => $a->weight ?? 0,
            ];
        }
    }

    // Comet signals.
    if (isset($data->comet->signals) && is_array($data->comet->signals)) {
        foreach ($data->comet->signals as $cs) {
            $weight = $cs->weight ?? $cs->maxWeight ?? 0;
            $signals[] = (object) [
                'name' => $cs->name,
                'value' => $cs->value ?? null,
                'weight' => $weight,
            ];
        }
    }

    // Fingerprint signals.
    if (isset($data->fingerprint->signals) && is_array($data->fingerprint->signals)) {
        foreach ($data->fingerprint->signals as $fs) {
            $signals[] = (object) [
                'name' => $fs->name,
                'value' => $fs->value ?? null,
                'weight' => $fs->weight ?? 0,
            ];
        }
    }

    // Injection signals.
    if (isset($data->injection->signals) && is_array($data->injection->signals)) {
        foreach ($data->injection->signals as $is) {
            $signals[] = (object) [
                'name' => $is->name,
                'value' => $is->value ?? null,
                'weight' => $is->maxWeight ?? $is->weight ?? 0,
            ];
        }
    }

    // Sort by weight descending and take the most significant ones.
    usort($signals, function ($a, $b) {
        return $b->weight - $a->weight;
    });

    // Deduplicate by name.
    $seen = [];
    foreach ($signals as $signal) {
        if (isset($seen[$signal->name])) {
            continue;
        }
        $seen[$signal->name] = true;

        $explanation = explain_signal($signal->name, $signal->value, $signal->weight);
        if ($explanation) {
            $explanations[] = $explanation;
        }

        // Cap at 8 explanations to keep it readable.
        if (count($explanations) >= 8) {
            break;
        }
    }

    return $explanations;
}

/**
 * Return a plain-language explanation for a detection signal.
 *
 * @param string $name The signal name.
 * @param mixed $value The signal value.
 * @param int $weight The signal weight.
 * @return string|null Human-readable explanation, or null if not mapped.
 */
function explain_signal(string $name, $value, int $weight): ?string {
    // Signal-to-explanation mapping.
    // Each entry describes what was detected in terms a teacher can understand.
    $map = [
        // Tier 1 — physically impossible for a human.
        'click.center_precision' =>
            'Clicks landed at the exact mathematical centre of page elements, '
            . 'a pattern consistent with programmatic clicking rather than a human hand.',
        'click.teleport_pattern' =>
            'The mouse cursor jumped instantly between distant screen positions without '
            . 'any intermediate movement — consistent with automated cursor positioning.',
        'comet.ultra_precise_center' =>
            'Multiple clicks hit the precise pixel centre of their target elements, '
            . 'which is extremely unlikely for a human using a mouse or trackpad.',
        'comet.low_mouse_to_action_ratio' =>
            'Very few mouse movements were recorded relative to the number of clicks. '
            . 'Human users naturally move the mouse before and between clicks.',
        'comet.zero_keystrokes' =>
            'No keyboard input was recorded during the session despite multiple clicks '
            . 'and page navigations — the session appeared to be driven entirely by clicking.',
        'comet.low_per_page_mouse_ratio' =>
            'Across most quiz pages, the number of mouse movements was extremely low '
            . 'compared to clicks — a strong indicator of programmatic interaction.',

        // Tier 2 — behavioural / temporal.
        'comet.action_burst' =>
            'Rapid bursts of actions (clicking answers in quick succession) were detected '
            . 'at a rate well above typical human quiz-taking speed.',
        'comet.read_then_act' =>
            'A repeated pattern of pausing (as if reading the question) followed by '
            . 'an immediate precise answer was detected across multiple questions.',
        'comet.no_mousemove_trail' =>
            'Clicks occurred without any mouse movement trail beforehand — human users '
            . 'almost always generate visible cursor movement before clicking.',
        'comet.missing_pointer_events' =>
            'Expected pointer interaction events (mouse down/up sequences) were missing '
            . 'or incomplete during click actions.',
        'comet.scroll_then_click' =>
            'A high proportion of clicks were immediately preceded by a scroll event, '
            . 'suggesting automated "scroll to element, then click" behaviour.',
        'comet.rapid_focus_sequence' =>
            'Page focus changed rapidly multiple times, consistent with an automated tool '
            . 'switching between browser tabs or windows.',

        // Click / interaction signals.
        'click.no_movement' =>
            'No mouse movement at all was detected during the session — all interaction '
            . 'consisted of clicks without any visible cursor activity.',
        'click.no_hover' =>
            'Click targets were never hovered over before being clicked, which is unusual '
            . 'for human mouse interaction.',
        'click.superhuman_speed' =>
            'Some clicks occurred faster than typical human reaction time allows.',
        'click.perfect_timing' =>
            'The timing between consecutive clicks was unusually uniform, suggesting '
            . 'automated pacing rather than natural human rhythm.',

        // Sequence signals.
        'sequence.low_hover_ratio' =>
            'The proportion of elements hovered before clicking was unusually low compared '
            . 'to typical human browsing patterns.',
        'sequence.direct_focus' =>
            'Form elements received focus directly without the preceding mouse movement '
            . 'that would normally occur with human navigation.',

        // Keystroke signals.
        'comet.uniform_keystroke_cadence' =>
            'Keystrokes were typed at a suspiciously uniform speed, lacking the natural '
            . 'variation in timing that human typing exhibits.',
        'comet.uniform_hold_duration' =>
            'Keys were held down for nearly identical durations across all keystrokes, '
            . 'which is atypical of natural human typing.',

        // Fingerprint / extension signals.
        'comet.extension.cached' =>
            'A known AI agent browser extension (Perplexity Comet) was detected as installed.',
        'comet.extension.script_injected' =>
            'Scripts associated with a known AI agent extension were found injected into the page.',
        'comet.extension.link_injected' =>
            'Resource links associated with a known AI agent extension were found in the page.',
        'comet.extension.stylesheet' =>
            'Stylesheets associated with a known AI agent extension were detected.',
        'comet.extension.resource_probe' =>
            'Probing for known AI agent extension resources returned a positive result.',
        'comet.runtime.inline_style' =>
            'Inline styles characteristic of an AI agent overlay were detected on the page.',
        'comet.runtime.script' =>
            'Runtime scripts characteristic of an AI agent were detected on the page.',
        'comet.runtime.global' =>
            'Global JavaScript variables associated with a known AI agent were found.',
    ];

    if (isset($map[$name])) {
        return $map[$name];
    }

    // Fallback for unmapped signals — show the technical name.
    return null;
}
