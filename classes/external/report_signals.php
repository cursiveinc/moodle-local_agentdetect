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
 * External API for reporting agent detection signals.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_agentdetect\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context;
use context_system;
use local_agentdetect\signal_manager;

/**
 * External function for reporting detection signals.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_signals extends external_api {
    /**
     * Describes the parameters for this function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sesskey' => new external_value(PARAM_ALPHANUMEXT, 'Session key'),
            'contextid' => new external_value(PARAM_INT, 'Context ID', VALUE_DEFAULT, 0),
            'sessionid' => new external_value(PARAM_ALPHANUMEXT, 'Detection session ID'),
            'signaltype' => new external_value(PARAM_ALPHA, 'Signal type'),
            'signaldata' => new external_value(PARAM_RAW, 'JSON-encoded signal data'),
        ]);
    }

    /**
     * Report detection signals.
     *
     * @param string $sesskey Session key for validation.
     * @param int $contextid Context ID.
     * @param string $sessionid Detection session ID.
     * @param string $signaltype Type of signal being reported.
     * @param string $signaldata JSON-encoded signal data.
     * @return array Result array.
     */
    public static function execute(
        string $sesskey,
        int $contextid,
        string $sessionid,
        string $signaltype,
        string $signaldata
    ): array {
        global $USER;

        // Parameter validation.
        $params = self::validate_parameters(self::execute_parameters(), [
            'sesskey' => $sesskey,
            'contextid' => $contextid,
            'sessionid' => $sessionid,
            'signaltype' => $signaltype,
            'signaldata' => $signaldata,
        ]);

        // Validate session key.
        if (!confirm_sesskey($params['sesskey'])) {
            return [
                'success' => false,
                'message' => 'Invalid session key',
            ];
        }

        // Validate context.
        if ($params['contextid'] > 0) {
            $context = context::instance_by_id($params['contextid'], IGNORE_MISSING);
        } else {
            $context = context_system::instance();
        }

        if (!$context) {
            return [
                'success' => false,
                'message' => 'Invalid context',
            ];
        }

        self::validate_context($context);

        // Decode and validate signal data.
        $data = json_decode($params['signaldata'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Invalid signal data format',
            ];
        }

        // Store the signal.
        try {
            $manager = new signal_manager();
            $result = $manager->store_signal(
                $USER->id,
                $context->id,
                $params['sessionid'],
                $params['signaltype'],
                $data
            );

            return [
                'success' => true,
                'message' => 'Signal recorded',
                'flagstatus' => $result['flag_status'] ?? 'none',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to store signal: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Describes the return value for this function.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'flagstatus' => new external_value(PARAM_ALPHA, 'Current flag status', VALUE_OPTIONAL),
        ]);
    }
}
