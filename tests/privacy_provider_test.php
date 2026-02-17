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
 * Unit tests for the privacy provider.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_agentdetect\privacy\provider
 */

namespace local_agentdetect;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_agentdetect\privacy\provider;

/**
 * Tests for the privacy provider.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Test that metadata is described correctly.
     */
    public function test_get_metadata(): void {
        $collection = new collection('local_agentdetect');
        $collection = provider::get_metadata($collection);

        $items = $collection->get_collection();
        $this->assertCount(2, $items);

        // Check table names are present.
        $tablenames = [];
        foreach ($items as $item) {
            $tablenames[] = $item->get_name();
        }
        $this->assertContains('local_agentdetect_signals', $tablenames);
        $this->assertContains('local_agentdetect_flags', $tablenames);
    }

    /**
     * Test that contexts are correctly identified for a user.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $manager = new signal_manager();
        $manager->store_signal($user->id, $coursecontext->id, 'privacy-test', 'combined',
            ['combinedscore' => 50, 'verdict' => 'SUSPICIOUS']);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $contextids = $contextlist->get_contextids();

        // Should include the course context and system context.
        $this->assertContains($coursecontext->id, $contextids);
        $this->assertContains(\context_system::instance()->id, $contextids);
    }

    /**
     * Test that users in a context are correctly identified.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $manager = new signal_manager();
        $manager->store_signal($user1->id, $coursecontext->id, 'privacy-u1', 'combined',
            ['combinedscore' => 50, 'verdict' => 'SUSPICIOUS']);
        $manager->store_signal($user2->id, $coursecontext->id, 'privacy-u2', 'combined',
            ['combinedscore' => 60, 'verdict' => 'SUSPICIOUS']);

        $userlist = new userlist($coursecontext, 'local_agentdetect');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertContains($user1->id, $userids);
        $this->assertContains($user2->id, $userids);
    }

    /**
     * Test data export for a user.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $manager = new signal_manager();
        $manager->store_signal($user->id, $coursecontext->id, 'export-test', 'combined',
            ['combinedscore' => 75, 'verdict' => 'HIGH_CONFIDENCE_AGENT']);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $approvedcontextlist = new approved_contextlist($user, 'local_agentdetect', $contextlist->get_contextids());

        provider::export_user_data($approvedcontextlist);

        $writer = writer::with_context($coursecontext);
        $data = $writer->get_data([get_string('pluginname', 'local_agentdetect'), 'signals']);
        $this->assertNotEmpty($data);
        $this->assertNotEmpty($data->signals);
    }

    /**
     * Test deleting data for a specific user.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $manager = new signal_manager();
        $manager->store_signal($user1->id, $coursecontext->id, 'del-u1', 'combined',
            ['combinedscore' => 80, 'verdict' => 'HIGH_CONFIDENCE_AGENT']);
        $manager->store_signal($user2->id, $coursecontext->id, 'del-u2', 'combined',
            ['combinedscore' => 70, 'verdict' => 'PROBABLE_AGENT']);

        // Delete user1's data.
        $contextlist = provider::get_contexts_for_userid($user1->id);
        $approvedcontextlist = new approved_contextlist($user1, 'local_agentdetect', $contextlist->get_contextids());
        provider::delete_data_for_user($approvedcontextlist);

        // User1's data should be gone.
        $this->assertEquals(0, $DB->count_records('local_agentdetect_signals', ['userid' => $user1->id]));
        $this->assertEquals(0, $DB->count_records('local_agentdetect_flags', ['userid' => $user1->id]));

        // User2's data should still exist.
        $this->assertGreaterThan(0, $DB->count_records('local_agentdetect_signals', ['userid' => $user2->id]));
    }

    /**
     * Test deleting all data in a context.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $manager = new signal_manager();
        $manager->store_signal($user->id, $coursecontext->id, 'delall', 'combined',
            ['combinedscore' => 50, 'verdict' => 'SUSPICIOUS']);

        provider::delete_data_for_all_users_in_context($coursecontext);

        $this->assertEquals(0, $DB->count_records('local_agentdetect_signals', ['contextid' => $coursecontext->id]));
        $this->assertEquals(0, $DB->count_records('local_agentdetect_flags', ['contextid' => $coursecontext->id]));
    }

    /**
     * Test deleting data for multiple users.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $manager = new signal_manager();
        $manager->store_signal($user1->id, $coursecontext->id, 'delmulti-1', 'combined',
            ['combinedscore' => 50, 'verdict' => 'SUSPICIOUS']);
        $manager->store_signal($user2->id, $coursecontext->id, 'delmulti-2', 'combined',
            ['combinedscore' => 60, 'verdict' => 'SUSPICIOUS']);

        // Delete only user1.
        $userlist = new userlist($coursecontext, 'local_agentdetect');
        provider::get_users_in_context($userlist);
        $approveduserlist = new approved_userlist($coursecontext, 'local_agentdetect', [$user1->id]);
        provider::delete_data_for_users($approveduserlist);

        $this->assertEquals(0, $DB->count_records('local_agentdetect_signals', [
            'userid' => $user1->id, 'contextid' => $coursecontext->id,
        ]));
        $this->assertGreaterThan(0, $DB->count_records('local_agentdetect_signals', [
            'userid' => $user2->id, 'contextid' => $coursecontext->id,
        ]));
    }
}
