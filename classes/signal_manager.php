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
 * Signal manager for storing and processing detection signals.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_agentdetect;

/**
 * Manages storage and processing of agent detection signals.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signal_manager {
    /** @var int Threshold score for automatic flagging. */
    const FLAG_THRESHOLD_HIGH = 70;

    /** @var int Threshold score for suspicious activity. */
    const FLAG_THRESHOLD_SUSPICIOUS = 40;

    /** @var string Flag type for suspected agent usage. */
    const FLAG_SUSPECTED = 'agent_suspected';

    /** @var string Flag type for confirmed agent usage. */
    const FLAG_CONFIRMED = 'agent_confirmed';

    /** @var string Flag type for cleared users. */
    const FLAG_CLEARED = 'cleared';

    /**
     * Store a detection signal and update flags if necessary.
     *
     * @param int $userid User ID.
     * @param int $contextid Context ID.
     * @param string $sessionid Detection session ID.
     * @param string $signaltype Type of signal.
     * @param array $data Signal data.
     * @return array Result with flag status.
     */
    public function store_signal(
        int $userid,
        int $contextid,
        string $sessionid,
        string $signaltype,
        array $data
    ): array {
        global $DB;

        // Extract scores from data.
        $fingerprintscore = $data['fingerprint']['score'] ?? $data['fingerprintscore'] ?? null;
        $interactionscore = $data['interaction']['score'] ?? $data['interactionscore'] ?? null;
        $combinedscore = $data['combinedScore'] ?? $data['combinedscore'] ?? null;
        $verdict = $data['verdict'] ?? null;

        // Build the record.
        $record = new \stdClass();
        $record->userid = $userid;
        $record->contextid = $contextid ?: null;
        $record->sessionid = $sessionid;
        $record->signaltype = $signaltype;
        $record->fingerprintscore = $fingerprintscore;
        $record->interactionscore = $interactionscore;
        $record->combinedscore = $combinedscore;
        $record->verdict = $verdict;
        $record->signaldata = json_encode($data);
        $record->useragent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $record->ipaddress = getremoteaddr();
        $record->timecreated = time();

        // Insert the signal record.
        $record->id = $DB->insert_record('local_agentdetect_signals', $record);

        // Update user flag if score warrants it.
        $flagstatus = $this->update_user_flag($userid, $contextid, $combinedscore, $sessionid);

        return [
            'signal_id' => $record->id,
            'flag_status' => $flagstatus,
        ];
    }

    /**
     * Update user flag based on detection score.
     *
     * @param int $userid User ID.
     * @param int|null $contextid Context ID.
     * @param int|null $score Combined detection score.
     * @param string $sessionid Session ID.
     * @return string Flag status.
     */
    protected function update_user_flag(
        int $userid,
        ?int $contextid,
        ?int $score,
        string $sessionid
    ): string {
        global $DB;

        if ($score === null || $score < self::FLAG_THRESHOLD_SUSPICIOUS) {
            return 'none';
        }

        $now = time();

        // Check for existing flag.
        $conditions = ['userid' => $userid];
        if ($contextid) {
            $conditions['contextid'] = $contextid;
        } else {
            // For null context, we need special handling.
            $sql = "userid = :userid AND contextid IS NULL";
            $params = ['userid' => $userid];
            $existingflag = $DB->get_record_select('local_agentdetect_flags', $sql, $params);
        }

        if (!isset($existingflag)) {
            $existingflag = $DB->get_record('local_agentdetect_flags', $conditions);
        }

        if ($existingflag) {
            // Update existing flag.
            $existingflag->detectioncount++;
            $existingflag->lastsessionid = $sessionid;
            $existingflag->timemodified = $now;

            if ($score > $existingflag->maxscore) {
                $existingflag->maxscore = $score;
            }

            // Escalate flag type if score is high enough.
            if ($score >= self::FLAG_THRESHOLD_HIGH && $existingflag->flagtype !== self::FLAG_CONFIRMED) {
                $existingflag->flagtype = self::FLAG_SUSPECTED;
            }

            $DB->update_record('local_agentdetect_flags', $existingflag);

            return $existingflag->flagtype;
        } else {
            // Create new flag.
            $flag = new \stdClass();
            $flag->userid = $userid;
            $flag->contextid = $contextid ?: null;
            $flag->flagtype = $score >= self::FLAG_THRESHOLD_HIGH ? self::FLAG_SUSPECTED : 'low_suspicion';
            $flag->maxscore = $score;
            $flag->detectioncount = 1;
            $flag->lastsessionid = $sessionid;
            $flag->timecreated = $now;
            $flag->timemodified = $now;

            $DB->insert_record('local_agentdetect_flags', $flag);

            return $flag->flagtype;
        }
    }

    /**
     * Get all flags for a user.
     *
     * @param int $userid User ID.
     * @return array Array of flag records.
     */
    public function get_user_flags(int $userid): array {
        global $DB;

        return $DB->get_records('local_agentdetect_flags', ['userid' => $userid], 'timemodified DESC');
    }

    /**
     * Get flag for a specific user and context.
     *
     * @param int $userid User ID.
     * @param int|null $contextid Context ID.
     * @return \stdClass|false Flag record or false.
     */
    public function get_flag(int $userid, ?int $contextid) {
        global $DB;

        if ($contextid === null) {
            $sql = "userid = :userid AND contextid IS NULL";
            return $DB->get_record_select('local_agentdetect_flags', $sql, ['userid' => $userid]);
        }

        return $DB->get_record('local_agentdetect_flags', [
            'userid' => $userid,
            'contextid' => $contextid,
        ]);
    }

    /**
     * Get all flagged users, optionally filtered by context.
     *
     * @param int|null $contextid Context ID to filter by.
     * @param string|null $flagtype Flag type to filter by.
     * @param int $minscore Minimum score to include.
     * @return array Array of flag records with user info.
     */
    public function get_flagged_users(
        ?int $contextid = null,
        ?string $flagtype = null,
        int $minscore = 0
    ): array {
        global $DB;

        $sql = "SELECT f.*, u.firstname, u.lastname, u.email
                  FROM {local_agentdetect_flags} f
                  JOIN {user} u ON u.id = f.userid
                 WHERE f.maxscore >= :minscore";
        $params = ['minscore' => $minscore];

        if ($contextid !== null) {
            $sql .= " AND f.contextid = :contextid";
            $params['contextid'] = $contextid;
        }

        if ($flagtype !== null) {
            $sql .= " AND f.flagtype = :flagtype";
            $params['flagtype'] = $flagtype;
        }

        $sql .= " ORDER BY f.maxscore DESC, f.timemodified DESC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get signal history for a user.
     *
     * @param int $userid User ID.
     * @param int $limit Maximum records to return.
     * @return array Array of signal records.
     */
    public function get_user_signals(int $userid, int $limit = 100): array {
        global $DB;

        return $DB->get_records(
            'local_agentdetect_signals',
            ['userid' => $userid],
            'timecreated DESC',
            '*',
            0,
            $limit
        );
    }

    /**
     * Get signals for a specific session.
     *
     * @param string $sessionid Session ID.
     * @return array Array of signal records.
     */
    public function get_session_signals(string $sessionid): array {
        global $DB;

        return $DB->get_records(
            'local_agentdetect_signals',
            ['sessionid' => $sessionid],
            'timecreated ASC'
        );
    }

    /**
     * Manually set a user flag.
     *
     * @param int $userid User ID to flag.
     * @param string $flagtype Flag type.
     * @param int|null $contextid Context ID.
     * @param string|null $notes Admin notes.
     * @param int $flaggedby User ID of admin setting the flag.
     * @return int Flag record ID.
     */
    public function set_flag(
        int $userid,
        string $flagtype,
        ?int $contextid = null,
        ?string $notes = null,
        int $flaggedby = 0
    ): int {
        global $DB;

        $existingflag = $this->get_flag($userid, $contextid);
        $now = time();

        if ($existingflag) {
            $existingflag->flagtype = $flagtype;
            $existingflag->notes = $notes;
            $existingflag->flaggedby = $flaggedby ?: null;
            $existingflag->timemodified = $now;

            $DB->update_record('local_agentdetect_flags', $existingflag);

            return $existingflag->id;
        } else {
            $flag = new \stdClass();
            $flag->userid = $userid;
            $flag->contextid = $contextid;
            $flag->flagtype = $flagtype;
            $flag->maxscore = 0;
            $flag->detectioncount = 0;
            $flag->notes = $notes;
            $flag->flaggedby = $flaggedby ?: null;
            $flag->timecreated = $now;
            $flag->timemodified = $now;

            return $DB->insert_record('local_agentdetect_flags', $flag);
        }
    }

    /**
     * Clear a user's flag.
     *
     * @param int $userid User ID.
     * @param int|null $contextid Context ID.
     * @param int $clearedby User ID of admin clearing the flag.
     * @return bool Success.
     */
    public function clear_flag(int $userid, ?int $contextid = null, int $clearedby = 0): bool {
        return $this->set_flag($userid, self::FLAG_CLEARED, $contextid, 'Cleared by admin', $clearedby) > 0;
    }
}
