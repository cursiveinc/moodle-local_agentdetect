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
 * Uninstall hook for local_agentdetect.
 *
 * Called before tables and settings are dropped during plugin removal.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Custom uninstall procedure.
 *
 * Moodle automatically handles dropping the plugin's tables and
 * config_plugins settings. This hook is provided for any additional
 * cleanup that may be needed in the future.
 *
 * @return bool True on success.
 */
function xmldb_local_agentdetect_uninstall() {
    return true;
}
