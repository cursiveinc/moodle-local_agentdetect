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
 * Restore plugin for local_agentdetect.
 *
 * Restores agent detection signals and flags into the target course.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore plugin class for local_agentdetect on the course level.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_agentdetect_plugin extends restore_local_plugin {
    /**
     * Define the plugin structure for restore.
     *
     * @return restore_path_element[] Array of restore path elements.
     */
    protected function define_course_plugin_structure() {
        $paths = [];

        $paths[] = new restore_path_element(
            'local_agentdetect_signal',
            $this->get_pathfor('/agentdetect_signals/signal')
        );
        $paths[] = new restore_path_element(
            'local_agentdetect_flag',
            $this->get_pathfor('/agentdetect_flags/flag')
        );

        return $paths;
    }

    /**
     * Process a restored signal record.
     *
     * @param array $data The signal data from backup.
     */
    public function process_local_agentdetect_signal(array $data): void {
        global $DB;

        $data = (object) $data;

        // Map the user ID to the new user.
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return; // User not mapped, skip this record.
        }

        // Map contextid to the new course context.
        $coursecontext = context_course::instance($this->task->get_courseid());
        $data->contextid = $coursecontext->id;

        unset($data->id);
        $DB->insert_record('local_agentdetect_signals', $data);
    }

    /**
     * Process a restored flag record.
     *
     * @param array $data The flag data from backup.
     */
    public function process_local_agentdetect_flag(array $data): void {
        global $DB;

        $data = (object) $data;

        // Map the user ID to the new user.
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return; // User not mapped, skip this record.
        }

        // Map flaggedby user ID if present.
        if (!empty($data->flaggedby)) {
            $data->flaggedby = $this->get_mappingid('user', $data->flaggedby);
        }

        // Map contextid to the new course context.
        $coursecontext = context_course::instance($this->task->get_courseid());
        $data->contextid = $coursecontext->id;

        // Check if a flag already exists for this user in this context.
        $existingflag = $DB->get_record('local_agentdetect_flags', [
            'userid' => $data->userid,
            'contextid' => $data->contextid,
        ]);

        if ($existingflag) {
            // Merge: keep the higher maxscore and add detection counts.
            if ($data->maxscore > $existingflag->maxscore) {
                $existingflag->maxscore = $data->maxscore;
                $existingflag->flagtype = $data->flagtype;
            }
            $existingflag->detectioncount += $data->detectioncount;
            $existingflag->timemodified = time();
            $DB->update_record('local_agentdetect_flags', $existingflag);
        } else {
            unset($data->id);
            $DB->insert_record('local_agentdetect_flags', $data);
        }
    }
}
