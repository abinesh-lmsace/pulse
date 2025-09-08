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
 * Scheduled cron task to setup the adhoc for user credit update.
 *
 * @package   pulseaddon_credits
 * @copyright 2024, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_credits\task;

use pulseaddon_credits\credits as creditsadhoc;

/**
 * Scheduled task to create the user credits using cron.
 */
class credits extends \core\task\scheduled_task {

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('updateusercredits', 'mod_pulse');
    }

    /**
     * Cron execution to send the available pulses.
     *
     * @return void
     */
    public function execute() {
        global $CFG;
        require_once($CFG->dirroot.'/mod/pulse/lib.php');

        self::prepare_adhoctask();
    }

    /**
     * Prepare and queue adhoc tasks for user credit updates.
     *
     * @return bool
     */
    public static function prepare_adhoctask(): bool {
        global $DB;

        // Check if credits field is empty.
        if (empty((array) creditsadhoc::creditsfield())) {
            return true;
        }

        // Get the module ID for 'pulse'.
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'pulse']);

        // SQL query to fetch pulse records with specific conditions.
        $sql = "SELECT p.id as pulseid, p.*, pp.value as credits_status, po.value as credits, cm.id as cmid
                FROM {pulse} p
                JOIN {pulse_options} po ON po.pulseid = p.id AND po.name = 'credits'
                JOIN {pulse_options} pp ON pp.pulseid = p.id AND pp.name = 'credits_status'
                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = :moduleid
                JOIN {course} cu ON cu.id = p.course
                WHERE cm.visible = 1 AND pp.value = '1' AND cu.visible = 1
                AND cu.startdate <= :startdate AND (cu.enddate = 0 OR cu.enddate >= :enddate)";

        // Execute the query and process the records.
        if ($records = $DB->get_records_sql($sql, ['moduleid' => $moduleid, 'startdate' => time(), 'enddate' => time()])) {
            foreach ($records as $pulseid => $record) {
                // Setup and queue adhoc task for each pulse instance.
                $task = new \pulseaddon_credits\credits();
                $task->set_custom_data((object) ['pulseid' => $pulseid]);
                $task->set_component('pulseaddon_credits');
                \core\task\manager::queue_adhoc_task($task, true);
            }
        }

        return true;
    }
}
