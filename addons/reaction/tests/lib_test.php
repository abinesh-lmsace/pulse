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
 * @package   pulseaddon_reaction
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_reaction;

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
        $this->setAdminUser();
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
     * Test reaction variables are updated.
     * @covers ::update_emailvars
     *
     * @return void
     */
    public function test_reaction_vars(): void {
        global $DB;
        $options = ['options' => ['reactiontype' => 1, 'reactiondisplay' => 1]];
        $this->create_pulse_module($options);
        // Enrol users.
        $user = $this->getDataGenerator()->create_user(['email' => 'testuser1@test.com', 'username' => 'testuser1']);
        $sender = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher', [
            'email' => 'sender1@test.com', 'username' => 'sender1',
        ]);
        $template = "{reaction}";
        $subject = '';
        list($subject, $template) = \mod_pulse\helper::update_emailvars(
            $template, $subject, $this->course, $user, $this->module, $sender
        );
        $token = $DB->get_field('pulseaddon_reaction_tokens', 'token', ['pulseid' => $this->module->id, 'userid' => $user->id]);
        $reactionurl = new moodle_url('/mod/pulse/addons/reaction/reaction.php', ['token' => $token]);
        $reactionurl = $reactionurl->out();
        $actualcontent = get_string('reaction:markcomplete', 'mod_pulse', ['reactionurl' => $reactionurl]);
        $this->assertEquals($actualcontent, $template);
    }

    /**
     *
     * Test delete instance are removed the pro datas related to the users.
     * @covers ::pulse_delete_instance
     * @return void
     */
    public function test_delete_instance(): void {
        global $DB;

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $options = ['options' => ['reactiontype' => 1, 'reactiondisplay' => 1], 'invitation_recipients' => $studentroleid,
            'intro' => '{reaction}'];

        $this->create_pulse_module($options);
        $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
            'email' => 'student1@test.com', 'username' => 'student1',
        ]);
        $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
            'email' => 'student2@test.com', 'username' => 'student2',
        ]);
        $this->getDataGenerator()->create_and_enrol($this->course, 'student', [
            'email' => 'student3@test.com', 'username' => 'student3',
        ]);

        $availabletime = new \mod_pulse\addon\notification();
        $instances = $availabletime->get_instances();
        (new \pulseaddon_availability\task\availabletime())->update_mod_availability($instances);

        phpunit_util::run_all_adhoc_tasks();
        $messages = $this->send_message();

        $tokenscount = $DB->count_records('pulseaddon_reaction_tokens', ['pulseid' => $this->module->id]);
        $this->assertEquals(3, $tokenscount);
        // Delete instance.
        course_delete_module($this->module->cmid);

        phpunit_util::run_all_adhoc_tasks();

        $this->assertCount(0, $DB->get_records('pulseaddon_reaction_tokens', ['pulseid' => $this->module->id]));
    }
}
