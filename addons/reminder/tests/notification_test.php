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
 * @copyright 2024 bdecent GmbH <https://bdecent.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_reminder;

/**
 * Pulse resource phpunit test cases defined.
 */
final class notification_test extends \advanced_testcase {

    /**
     * Module intro content.
     *
     * @var string
     */
    public $defaultdata = '';

    /**
     * Test plugin pulseaddon report genretor class.
     *
     * @var mixed
     */
    public $generator;

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
        $this->coursecontext = \context_course::instance($this->course->id);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('pulseaddon_reminder');
        $this->defaultdata = $this->generator->default_data($this->course->id);

    }

    /**
     * Create pulse module with pro features.
     *
     * @param  mixed $options Module configs specified for test.
     * @return void
     */
    public function create_pulse_module($options=[]) {
        $this->module = $this->getDataGenerator()->create_module('pulse', $this->defaultdata, $options);
        $this->cm = get_coursemodule_from_instance('pulse', $this->module->id);
    }

    /**
     * Send reminder - run the adhoc task and returns the messages and task list.
     *
     * @return void
     */
    public function send_message() {
        $this->preventResetByRollback();
        $slink = $this->redirectMessages();
        // Setup adhoc task to send notifications.
        // Check adhock task count.
        $tasklist = \core\task\manager::get_adhoc_tasks('\pulseaddon_reminder\task\sendreminders');
        // Run all adhoc task to send notification.
        \phpunit_util::run_all_adhoc_tasks();
        $messages = $slink->get_messages();
        return ['tasklist' => $tasklist, 'messages' => $messages];
    }

    /**
     * Get role id from role shortname.
     *
     * @param  mixed $name Role shortname
     * @return int $studnetroleid Role id.
     */
    public function get_roleid($name='student') {
        global $DB;
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => $name]);
        return $studentroleid;
    }

    /**
     * Create student users and enrol into course.
     *
     * @param  mixed $count Students count to create.
     * @return void
     */
    public function create_students($count=1) {
        for ($i = 1; $i <= $count; $i++) {
            $user1 = $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
                'email' => 'student'.$i.'@test.com', 'username' => 'student'.$i,
            ]);
        }
    }

    /**
     * Create users with teacher role and enrol into course.
     *
     * @param  mixed $count Number teachers to create
     * @return void
     */
    public function create_teachers($count=1) {
        for ($i = 1; $i <= $count; $i++) {
            $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher', [
                'email' => 'teacher'.$i.'@test.com', 'username' => 'teacher'.$i,
            ]);
        }
    }

    /**
     * Get notification instance.
     *
     * @return \pulseaddon_reminder\notification
     */
    public function get_notification() {
         // Notification.
        $notification = new \pulseaddon_reminder\notification();
        $instances = $notification->get_instances();
        (new \pulseaddon_availability\task\availabletime())->update_mod_availability($instances);

        \phpunit_util::run_all_adhoc_tasks();

        return $notification;
    }
    /**
     * Test the first reminders send the message for student roles.
     * @covers ::first_reminder
     * @return void
     */
    public function test_first_reminder(): void {

        $studentroleid = $this->get_roleid();
        $options = ['first_reminder' => 1, 'first_recipients' => $studentroleid];

        $this->create_pulse_module($options);
        $this->create_students(3);
        $this->create_teachers(2);

        // Notification.
        $notification = new \pulseaddon_reminder\notification();
        $instances = $notification->get_instances();
        (new \pulseaddon_availability\task\availabletime())->update_mod_availability($instances);

        \phpunit_util::run_all_adhoc_tasks();

        $notification->first_reminder();
        $messages = $this->send_message();
        $this->assertCount(3, $messages['messages']);
    }

    /**
     * Test the first reminders send the message for teacher roles.
     * @covers ::first_reminder
     * @return void
     */
    public function test_first_reminder_editingteachers(): void {
        $roleid = $this->get_roleid('editingteacher');
        $options = [
            'first_content' => 'Mail for user {User_Email}',
            'first_reminder' => 1,
            'first_recipients' => $roleid,
        ];
        $this->create_pulse_module($options);
        $this->create_students(2);
        $this->create_teachers(3);

        $notification = new \pulseaddon_reminder\notification();
        $instances = $notification->get_instances();
        (new \pulseaddon_availability\task\availabletime())->update_mod_availability($instances);

        \phpunit_util::run_all_adhoc_tasks();

        $notification->first_reminder();
        $messages = $this->send_message();
        $this->assertCount(6, $messages['messages']);
    }

    /**
     * Test the reminders schedule are send in duration.
     * @covers ::reminder_schedule
     * @return void
     */
    public function test_reminder_schedule(): void {
        $roleid = $this->get_roleid();
        $options = [
            'first_reminder' => 1,
            'first_recipients' => $roleid,
            'second_reminder' => 1,
            'second_recipients' => $roleid,
            'first_schedule' => 0,
            'first_fixeddate' => strtotime('+1 day'),
            'second_schedule' => 0,
            'second_fixeddate' => strtotime('-1 day'),
        ];
        $this->create_pulse_module($options);
        $this->create_students(3);

        $notification = new \pulseaddon_reminder\notification();
        $instances = $notification->get_instances();
        (new \pulseaddon_availability\task\availabletime())->update_mod_availability($instances);

        \phpunit_util::run_all_adhoc_tasks();

        $notification->first_reminder();

        $messages = $this->send_message();
        $this->assertCount(0, $messages['messages']);
        $notification->second_reminder();
        $messages = $this->send_message();
        $message = reset($messages['messages']);
        $this->assertEquals('Second reminder content', $message->fullmessage);
        $this->assertCount(3, $messages['messages']);
    }

    /**
     * Test the second reminders send the message for student roles.
     * @covers ::second_reminder
     * @return void
     */
    public function test_second_reminder(): void {
        $studentroleid = $this->get_roleid();
        $options = [
            'second_content' => 'Second reminder content',
            'second_reminder' => 1,
            'second_recipients' => $studentroleid,
        ];
        $this->create_pulse_module($options);
        $this->create_students(3);
        $this->create_teachers(2);

        $notification = $this->get_notification();
        $notification->second_reminder();

        $messages = $this->send_message();
        $message = reset($messages['messages']);

        $this->assertEquals('Second reminder content', $message->fullmessage);
        $this->assertCount(3, $messages['messages']);
    }

    /**
     * Test the second reminders send the message for teacher roles.
     * @covers ::second_reminder_editingteachers
     * @return void
     */
    public function test_second_reminder_editingteachers(): void {
        $roleid = $this->get_roleid('editingteacher');
        $options = ['second_content' => 'Mail for user {User_Email}', 'second_reminder' => 1, 'second_recipients' => $roleid];
        $this->create_pulse_module($options);
        $this->create_students(2);
        $this->create_teachers(3);

        $notification = $this->get_notification();

        $notification->second_reminder();
        $messages = $this->send_message();
        $this->assertCount(6, $messages['messages']);
    }
}
