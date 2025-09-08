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
 * Pulse instance test cases defined.
 *
 * @package   pulseaddon_reminder
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_reminder;

use context_course;
use phpunit_util;
use moodle_url;

/**
 * Pulse resource phpunit test cases defined.
 */
final class lib_test extends \advanced_testcase {

    /**
     * Course instance data
     *
     * @var stdclass
     */
    public $course;

    /**
     * Module instance data
     *
     * @var stdclass
     */
    public $module;

    /**
     * Course module instance data
     *
     * @var stdclass
     */
    public $cm;

    /**
     * Course context data
     *
     * @var \context_course
     */
    public $coursecontext;

    /**
     * Module intro content.
     *
     * @var string
     */
    public $intro = 'Pulse test notification';

    /**
     * Setup testing cases.
     *
     * @return void
     */
    public function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Remove the output display of cron task.
        $CFG->mtrace_wrapper = 'mod_pulse_remove_mtrace_output';
        $this->course = $this->getDataGenerator()->create_course();
        $this->coursecontext = context_course::instance($this->course->id);
    }

    /**
     * Create pulse module with pro features.
     *
     * @param  mixed $options Module configs specified for test.
     * @return void
     */
    public function create_pulse_module($options=[]) {
        $data = ['course' => $this->course] + $options;
        $this->module = $this->getDataGenerator()->create_module('pulse', $data, $options);
        $this->cm = get_coursemodule_from_instance('pulse', $this->module->id);
    }

    /**
     * Send messages.
     *
     * @return void
     */
    public function send_message() {
        $this->preventResetByRollback();
        $slink = $this->redirectMessages();
        // Setup adhoc task to send notifications.
        \mod_pulse\task\notify_users::pulse_cron_task(true);
        // Run all adhoc task to send notification.
        phpunit_util::run_all_adhoc_tasks();
    }

    /**
     * Test create instance of pulse module creates the pulsepro features.
     * @covers ::pulse_add_instance
     * @return void
     */
    public function test_create_instance(): void {
        global $DB;
        $this->create_pulse_module();

        $result = (object) $DB->get_record('pulseaddon_reminder', ['pulseid' => $this->module->id]);

        $this->assertEquals('First reminder content', $result->first_content);
        $this->assertEquals('Second reminder content', $result->second_content);
        $this->assertEquals('Recurring reminder content', $result->recurring_content);
    }

    /**
     * Test course users are fetched.
     * @covers ::local_pulsepro_get_users
     * @return void
     */
    public function test_get_course_users(): void {
        global $DB;
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $this->create_pulse_module(['first_reminder' => 1, 'first_recipients' => $studentroleid]);

        $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
            'email' => 'student1@test.com', 'username' => 'student1',
        ]);
        $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
            'email' => 'student2@test.com', 'username' => 'student2',
        ]);
        $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
            'email' => 'student3@test.com', 'username' => 'student3',
        ]);
        $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher', [
            'email' => 'sender1@test.com', 'username' => 'sender1',
        ]);

        $notification = new \pulseaddon_reminder\notification();
        $instances = $notification->get_instances();
        (new \pulseaddon_availability\task\availabletime())->update_mod_availability($instances);

        phpunit_util::run_all_adhoc_tasks();

        foreach ($instances as $instance) {
            $userslist = $notification->get_student_users($instance, 'first');
            $this->assertCount(3, $userslist);
        }
    }

    /**
     *
     * Test delete instance are removed the pro datas related to the users.
     * @covers ::pulse_delete_instance
     * @return void
     */
    public function test_delete_instance(): void {
        global $DB, $CFG;

        global $DB, $CFG;

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $options = ['invitation_recipients' => $studentroleid, 'intro' => '{reaction}'];

        $this->create_pulse_module($options);
        $user1 = $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
            'email' => 'student1@test.com', 'username' => 'student1',
        ]);
        $user2 = $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
            'email' => 'student2@test.com', 'username' => 'student2',
        ]);
        $user2 = $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
            'email' => 'student3@test.com', 'username' => 'student3',
        ]);

        $availabletime = new \mod_pulse\addon\notification();
        $instances = $availabletime->get_instances();
        (new \pulseaddon_availability\task\availabletime())->update_mod_availability($instances);

        phpunit_util::run_all_adhoc_tasks();

        $this->send_message();

        $pro = $DB->count_records('pulseaddon_reminder', ['pulseid' => $this->module->id]);
        $this->assertEquals(1, $pro);

        $availability = $DB->count_records('pulseaddon_availability', ['pulseid' => $this->module->id]);
        $this->assertEquals(3, $availability);

        // Delete instance.
        course_delete_module($this->module->cmid);

        phpunit_util::run_all_adhoc_tasks();

        $this->assertCount(0, $DB->get_records('pulseaddon_availability', ['pulseid' => $this->module->id]));
        $this->assertCount(0, $DB->get_records('pulseaddon_reminder', ['pulseid' => $this->module->id]));
    }

    /**
     * Test the get instance list are returned corrent instance data.
     * @covers ::local_pulsepro_course_instancelist
     * @return void
     */
    public function test_instancelist(): void {
        $this->create_pulse_module(['name' => 'First pulse pro']);
        $this->create_pulse_module();

        $availabletime = new \mod_pulse\addon\notification();
        $instances = $availabletime->get_instances();
        $first = reset($instances);
        $this->assertCount(2, $instances);
        $this->assertEquals('First pulse pro', $first->pulse->name);
    }

}
