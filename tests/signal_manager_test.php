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
 * Unit tests for the signal_manager class.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_agentdetect\signal_manager
 */

namespace local_agentdetect;

/**
 * Tests for the signal_manager class.
 *
 * @package    local_agentdetect
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signal_manager_test extends \advanced_testcase {
    /**
     * Test storing a signal below the suspicious threshold creates no flag.
     */
    public function test_store_signal_below_threshold(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $manager = new signal_manager();
        $result = $manager->store_signal(
            $user->id,
            0,
            'test-session-1',
            'combined',
            ['combinedscore' => 20, 'verdict' => 'LIKELY_HUMAN']
        );

        $this->assertArrayHasKey('signal_id', $result);
        $this->assertEquals('none', $result['flag_status']);

        // No flag should be created.
        $flags = $manager->get_user_flags($user->id);
        $this->assertEmpty($flags);
    }

    /**
     * Test storing a signal above the suspicious threshold creates a low_suspicion flag.
     */
    public function test_store_signal_suspicious_threshold(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $manager = new signal_manager();
        $result = $manager->store_signal(
            $user->id,
            0,
            'test-session-2',
            'combined',
            ['combinedscore' => 50, 'verdict' => 'SUSPICIOUS']
        );

        $this->assertEquals('low_suspicion', $result['flag_status']);

        $flags = $manager->get_user_flags($user->id);
        $this->assertCount(1, $flags);
        $flag = reset($flags);
        $this->assertEquals('low_suspicion', $flag->flagtype);
        $this->assertEquals(50, $flag->maxscore);
    }

    /**
     * Test storing a signal above the high threshold creates an agent_suspected flag.
     */
    public function test_store_signal_high_threshold(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $manager = new signal_manager();
        $result = $manager->store_signal(
            $user->id,
            0,
            'test-session-3',
            'combined',
            ['combinedscore' => 85, 'verdict' => 'HIGH_CONFIDENCE_AGENT']
        );

        $this->assertEquals('agent_suspected', $result['flag_status']);

        $flags = $manager->get_user_flags($user->id);
        $this->assertCount(1, $flags);
        $flag = reset($flags);
        $this->assertEquals('agent_suspected', $flag->flagtype);
        $this->assertEquals(85, $flag->maxscore);
        $this->assertEquals(1, $flag->detectioncount);
    }

    /**
     * Test that repeated signals increment the detection count and update max score.
     */
    public function test_store_signal_increments_detection_count(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $manager = new signal_manager();

        // First signal — suspicious.
        $manager->store_signal($user->id, 0, 'session-a', 'combined',
            ['combinedscore' => 50, 'verdict' => 'SUSPICIOUS']);

        // Second signal — higher score.
        $manager->store_signal($user->id, 0, 'session-b', 'combined',
            ['combinedscore' => 60, 'verdict' => 'SUSPICIOUS']);

        $flags = $manager->get_user_flags($user->id);
        $this->assertCount(1, $flags);
        $flag = reset($flags);
        $this->assertEquals(2, $flag->detectioncount);
        $this->assertEquals(60, $flag->maxscore);
    }

    /**
     * Test flag escalation from low_suspicion to agent_suspected.
     */
    public function test_flag_escalation(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $manager = new signal_manager();

        // Start with low suspicion.
        $result1 = $manager->store_signal($user->id, 0, 'session-c', 'combined',
            ['combinedscore' => 45, 'verdict' => 'SUSPICIOUS']);
        $this->assertEquals('low_suspicion', $result1['flag_status']);

        // Escalate to agent_suspected with high score.
        $result2 = $manager->store_signal($user->id, 0, 'session-d', 'combined',
            ['combinedscore' => 80, 'verdict' => 'HIGH_CONFIDENCE_AGENT']);
        $this->assertEquals('agent_suspected', $result2['flag_status']);

        $flags = $manager->get_user_flags($user->id);
        $flag = reset($flags);
        $this->assertEquals('agent_suspected', $flag->flagtype);
        $this->assertEquals(80, $flag->maxscore);
    }

    /**
     * Test manually setting a flag.
     */
    public function test_set_flag_manually(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $admin = $this->getDataGenerator()->create_user();

        $manager = new signal_manager();
        $flagid = $manager->set_flag(
            $user->id,
            signal_manager::FLAG_CONFIRMED,
            null,
            'Manual confirmation after review',
            $admin->id
        );

        $this->assertGreaterThan(0, $flagid);

        $flag = $manager->get_flag($user->id, null);
        $this->assertEquals('agent_confirmed', $flag->flagtype);
        $this->assertEquals('Manual confirmation after review', $flag->notes);
        $this->assertEquals($admin->id, $flag->flaggedby);
    }

    /**
     * Test clearing a flag.
     */
    public function test_clear_flag(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $admin = $this->getDataGenerator()->create_user();

        $manager = new signal_manager();

        // Create a flag first.
        $manager->store_signal($user->id, 0, 'session-e', 'combined',
            ['combinedscore' => 80, 'verdict' => 'HIGH_CONFIDENCE_AGENT']);

        // Clear it.
        $result = $manager->clear_flag($user->id, null, $admin->id);
        $this->assertTrue($result);

        $flag = $manager->get_flag($user->id, null);
        $this->assertEquals('cleared', $flag->flagtype);
    }

    /**
     * Test context-specific flags remain separate.
     */
    public function test_context_specific_flags(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $ctx1 = \context_course::instance($course1->id);
        $ctx2 = \context_course::instance($course2->id);

        $manager = new signal_manager();

        // Flag in course 1.
        $manager->store_signal($user->id, $ctx1->id, 'session-f', 'combined',
            ['combinedscore' => 80, 'verdict' => 'HIGH_CONFIDENCE_AGENT']);

        // Flag in course 2 with lower score.
        $manager->store_signal($user->id, $ctx2->id, 'session-g', 'combined',
            ['combinedscore' => 50, 'verdict' => 'SUSPICIOUS']);

        $flags = $manager->get_user_flags($user->id);
        $this->assertCount(2, $flags);

        $flag1 = $manager->get_flag($user->id, $ctx1->id);
        $this->assertEquals('agent_suspected', $flag1->flagtype);

        $flag2 = $manager->get_flag($user->id, $ctx2->id);
        $this->assertEquals('low_suspicion', $flag2->flagtype);
    }

    /**
     * Test that signal_detected event is triggered.
     */
    public function test_signal_detected_event(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $sink = $this->redirectEvents();

        $manager = new signal_manager();
        $manager->store_signal($user->id, 0, 'session-event', 'combined',
            ['combinedscore' => 30, 'verdict' => 'LIKELY_HUMAN']);

        $events = $sink->get_events();
        $sink->close();

        // Find our signal_detected event.
        $found = false;
        foreach ($events as $event) {
            if ($event instanceof \local_agentdetect\event\signal_detected) {
                $found = true;
                $this->assertEquals($user->id, $event->relateduserid);
                $this->assertEquals(30, $event->other['combinedscore']);
                $this->assertEquals('LIKELY_HUMAN', $event->other['verdict']);
                break;
            }
        }
        $this->assertTrue($found, 'signal_detected event should have been triggered');
    }

    /**
     * Test that user_flagged event is triggered when a new flag is created.
     */
    public function test_user_flagged_event(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $sink = $this->redirectEvents();

        $manager = new signal_manager();
        $manager->store_signal($user->id, 0, 'session-flag-event', 'combined',
            ['combinedscore' => 85, 'verdict' => 'HIGH_CONFIDENCE_AGENT']);

        $events = $sink->get_events();
        $sink->close();

        // Find our user_flagged event.
        $found = false;
        foreach ($events as $event) {
            if ($event instanceof \local_agentdetect\event\user_flagged) {
                $found = true;
                $this->assertEquals($user->id, $event->relateduserid);
                $this->assertEquals('agent_suspected', $event->other['flagtype']);
                break;
            }
        }
        $this->assertTrue($found, 'user_flagged event should have been triggered');
    }

    /**
     * Test get_session_signals returns signals in correct order.
     */
    public function test_get_session_signals(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $manager = new signal_manager();

        $manager->store_signal($user->id, 0, 'session-order', 'fingerprint',
            ['fingerprintscore' => 10]);
        $manager->store_signal($user->id, 0, 'session-order', 'interaction',
            ['interactionscore' => 20]);
        $manager->store_signal($user->id, 0, 'session-order', 'combined',
            ['combinedscore' => 15, 'verdict' => 'LIKELY_HUMAN']);

        $signals = $manager->get_session_signals('session-order');
        $this->assertCount(3, $signals);

        $types = array_column($signals, 'signaltype');
        $this->assertEquals(['fingerprint', 'interaction', 'combined'], array_values($types));
    }

    /**
     * Test get_flagged_users with filters.
     */
    public function test_get_flagged_users(): void {
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $manager = new signal_manager();

        $manager->store_signal($user1->id, 0, 's1', 'combined',
            ['combinedscore' => 85, 'verdict' => 'HIGH_CONFIDENCE_AGENT']);
        $manager->store_signal($user2->id, 0, 's2', 'combined',
            ['combinedscore' => 50, 'verdict' => 'SUSPICIOUS']);

        // Get all flagged users.
        $flagged = $manager->get_flagged_users();
        $this->assertCount(2, $flagged);

        // Filter by minimum score.
        $flagged = $manager->get_flagged_users(null, null, 70);
        $this->assertCount(1, $flagged);
        $flag = reset($flagged);
        $this->assertEquals($user1->id, $flag->userid);

        // Filter by flag type.
        $flagged = $manager->get_flagged_users(null, 'low_suspicion');
        $this->assertCount(1, $flagged);
        $flag = reset($flagged);
        $this->assertEquals($user2->id, $flag->userid);
    }
}
