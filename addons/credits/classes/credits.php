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
 * Process module credits adhoc task.
 *
 * @package   pulseaddon_credits
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_credits;

use mod_pulse\addon\util;
use stdclass;

/**
 * Process module credits to enrolled users.
 */
class credits extends \core\task\adhoc_task {

    /**
     * Selected user custom profile field name.
     *
     * @var string
     */
    public $profilefieldname = '';

    /**
     * Selected user custom profile field id.
     *
     * @var int
     */
    public $profilefieldid = 0;

    /**
     * User credits adhoc task execution.
     *
     * @return void
     */
    public function execute() {
        global $CFG;

        require_once($CFG->dirroot . '/mod/pulse/lib.php');

        $pulseid = $this->get_custom_data()->pulseid;
        if (empty($pulseid)) {
            return false;
        }

        $instance = $this->prepare_instance($pulseid);
        if (!empty($instance->users)) {
            $this->update_usercredits($instance, $instance->users);
        }

        pulse_mtrace('Init credits - '.$instance->cmid);

        return true;
    }

    /**
     * Add and update the user credit score to user profile field and pulse credits table.
     *
     * @param stdclass $instance Pulse module with coursecontext and pulseaddon data.
     * @param array $users Enrolled users in module/course.
     * @param bool $instant Check user availability using moodle core not from availbility table.
     * @return void
     */
    public function update_usercredits($instance, $users=[], $instant=false) {
        global $DB;

        $creditfield = (object) self::creditsfield(true);

        if (!empty((array) $creditfield) && $DB->record_exists('pulse', ['id' => $instance->pulseid])) {

            $instance->course = get_course($instance->course);
            $modcontext = \context_module::instance($instance->cmid);
            $modcredits = intval($instance->credits);
            if (empty($users)) {
                $users = $this->coursestudents($modcontext, $instance->pulseid);
            }
            $this->profilefieldid = $creditfield->id;
            $this->profilefieldname = $creditfield->shortname;
            // 1.get list of already updated users to prevent the reassign.
            $updatedusers = $this->get_listof_updatedusers($instance->pulseid);
            $updatedusersid = array_keys($updatedusers);

            $fieldinfocreated = $this->get_listof_userprofilefields();
            $availabilities = \pulseaddon_availability\task\availability::fetch_available_users($instance->pulseid);
            $filteredusers = array_filter($users, function($user) use ($updatedusersid) {
                return (!in_array($user->id, $updatedusersid)) ? true : false;
            });

            if (!empty($filteredusers)) {
                $insertrecords = [];

                foreach ($filteredusers as $userid => $user) {
                    if ($instant) {
                        if (!$this->is_user_visible($instance, $user)) {
                            unset($users[$userid]);
                            continue;
                        }
                    } else if (!in_array($userid, array_keys($availabilities))) {
                        unset($users[$userid]);
                        continue;
                    }

                    if (in_array($user->id, array_keys($fieldinfocreated))) {
                        $prevcredit = (float) $fieldinfocreated[$user->id]->data;
                        if (!is_numeric($prevcredit) || !is_numeric($modcredits)) {
                            continue;
                        }
                        $updatedcredits = $prevcredit + $modcredits;
                        $DB->set_field('user_info_data', 'data', $updatedcredits,
                            ['fieldid' => $this->profilefieldid, 'userid' => $userid]
                        );
                    } else {
                        $this->prepare_userfielddata_inserts($user, $modcredits, $insertrecords);
                    }

                    // Local pulse pro credit.
                    // User is not aleady updated with module credit then add this mod credit.
                    if (!in_array($user->id, $updatedusersid)) {
                        $this->addcredit_record($instance->pulseid, $creditfield, $user, $modcredits, $insertrecords);
                    }
                }

                if (isset($insertrecords['pulse_credits']) && !empty($insertrecords['pulse_credits'])) {
                    $DB->insert_records('pulseaddon_credits', $insertrecords['pulse_credits']);
                }

                // Insert new records available for user profile field.
                if (isset($insertrecords['user_info']) && !empty($insertrecords['user_info'])) {
                    $DB->insert_records('user_info_data', $insertrecords['user_info']);
                }
            }

            $task = new self();
            $task->set_custom_data((object) ['pulseid' => $instance->pulseid]);
            $task->set_component('pulseaddon_credits');
            \core\task\manager::reschedule_or_queue_adhoc_task($task);
        }

        return true;
    }

    /**
     * Fetch user all available module credits from pulse credits table.
     *
     * @param int $userid ID of user.
     * @return float User total credits.
     */
    public function fetch_usermodcredits(int $userid) {
        global $DB;
        if ($records = $DB->get_records('pulseaddon_credits', ['userid' => $userid])) {
            $credits = 0;
            foreach ($records as $record) {
                $credits = $credits + $record->credit;
            }
            return $credits;
        }
    }

    /**
     * Create insertable records for user credit value to custom user profile field table.
     *
     * @param stdclass $user User record.
     * @param float $modcredits Moduel credit score.
     * @param array $insertrecords List of records need to insert in user info data table.
     * @return void
     */
    public function prepare_userfielddata_inserts($user, $modcredits, &$insertrecords) {
        if (isset($this->profilefieldid)) {
            $insertrecords['user_info'][] = ['userid' => $user->id, 'fieldid' => $this->profilefieldid, 'data' => $modcredits];
        }
    }

    /**
     * Create insertable records for user credit value to pulse credit table.
     *
     * @param int $pulseid Pulse instance id.
     * @param \stdclass $creditfield Selected creditfield.
     * @param \stdclass $user User record to update credit.
     * @param float $credit Current user credit for module.
     * @param array $insertrecords Available insertable records.
     * @return void
     */
    public function addcredit_record(int $pulseid, \stdclass $creditfield, \stdclass $user, float $credit, array &$insertrecords) {
        $profilefield = $creditfield->shortname;
        $insertrecords['pulse_credits'][] = [
            'userid' => $user->id,
            'pulseid' => $pulseid,
            'credit' => $credit,
            'timecreated' => time(),
        ];
    }

    /**
     * Calculate new credit score for user.
     *
     * @param float $newcredit Current module credit scores.
     * @param stdclass $user User record.
     * @param stdclass $creditfield Selected credit profile field record.
     * @return void
     */
    public function usernewprofilecredit(&$newcredit, $user, $creditfield): void {
        $profilefield = $creditfield->shortname;
        if (isset($user->$profilefield)) {
            $newcredit = (is_numeric($user->$profilefield)) ? $user->$profilefield + $newcredit : $newcredit;
        }
    }

    /**
     * List of student users enrolled in course with capability to view module context.
     *
     * @param \context $modcontext Pulse Module context.
     * @param int $pulseid Pulse instance id.
     * @return array List of enrolled users.
     */
    public function coursestudents(\context $modcontext, int $pulseid) {
        global $CFG, $DB;
        require_once($CFG->dirroot.'/user/profile/lib.php');

        $capability = 'mod/pulse:notifyuser';

        // Remove the already notified users.
        $additionalcondition[] = ' u.id NOT IN (SELECT userid FROM {pulseaddon_credits} WHERE pulseid = :pulseid)';

        $limit = get_config('mod_pulse', 'tasklimituser') ?: 100;

        $enrolledusers = util::get_enrolled_users_sql(
            $modcontext, $capability, 0, 'u.*', null, 0, $limit, true, $additionalcondition, ['pulseid' => $pulseid]);

        array_walk($enrolledusers, function($value) {
            return profile_load_data($value);
        });
        return $enrolledusers;
    }

    /**
     * Check the user has access to view the activity instance.
     *
     * @param stdclass $instance Course module instance.
     * @param stdclass $student User record.
     * @return bool True if module visible to shared user.
     */
    public function is_user_visible($instance, $student) {
        $modinfo = \course_modinfo::instance((object) $instance->course, $student->id);
        $cm = $modinfo->get_cm($instance->cmid);
        if ($cm->uservisible) {
            return true;
        }
        return false;
    }

    /**
     * Fetch list of updated users record for pulseid.
     *
     * @param int $pulseid Pulse id.
     * @return array User credit scores.
     */
    public function get_listof_updatedusers(int $pulseid): array {
        global $DB;
        if ($users = $DB->get_records('pulseaddon_credits', ['pulseid' => $pulseid], '', 'userid, id, credit')) {
            return $users;
        }
        return [];
    }

    /**
     * Fetch list of users field already inserted in user_info_data.
     *
     * @return array
     */
    public function get_listof_userprofilefields(): array {
        global $DB;
        // List of users.
        $fieldinfocreated = $DB->get_records('user_info_data', ['fieldid' => $this->profilefieldid], '', 'userid, id, data');
        return ($fieldinfocreated) ? $fieldinfocreated : [];
    }

    /**
     * Prepate available instance for credit enabled pulse records and setup adhoc task.
     *
     * @param int $pulseid Pulse instance id.
     * @return stdClass|bool
     */
    public function prepare_instance($pulseid): stdClass {
        global $DB;

        if (empty((array) self::creditsfield())) {
            return true;
        }

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'pulse']);

        $sql = "SELECT p.id as pulseid, p.*, pp.value as credits_status, po.value as credits, cm.id as cmid, cm.*
                FROM {pulse} p
                JOIN {pulse_options} po ON po.pulseid = p.id AND po.name = 'credits'
                JOIN {pulse_options} pp ON pp.pulseid = p.id AND pp.name = 'credits_status'
                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = :moduleid
                JOIN {course} cu ON cu.id = p.course
                WHERE p.id = :pulseid AND cm.visible = 1 AND pp.value = '1' AND cu.visible = 1
                AND cu.startdate <= :startdate AND (cu.enddate = 0 OR cu.enddate >= :enddate)";

        // ... TODO: add course startdate and enddate test.
        if ($record = $DB->get_record_sql($sql, [
            'pulseid' => $pulseid, 'moduleid' => $moduleid, 'startdate' => time(), 'enddate' => time()], IGNORE_MULTIPLE)) {
            // Setup each adhoc task for each pulse instance.
            $this->get_users_forinstance($record);
            return $record;
        }
        return true;
    }

    /**
     * Setup the adhoc task for each instance.
     *
     * @param stdclass $instance Module instance with pulse, course_modules, pulseaddon record.
     * @return bool
     */
    public function get_users_forinstance(stdclass &$instance): bool {

        $modcontext = \context_module::instance($instance->cmid);

        $users = $this->coursestudents($modcontext, $instance->pulseid);

        if (empty($users)) {
            return false;
        }
        // Filter already credits updated user and user doesn't have module available.
        $updatedusers = $this->get_listof_updatedusers($instance->pulseid);
        $updatedusersid = array_keys($updatedusers);

        $availabilities = \pulseaddon_availability\task\availability::fetch_available_users($instance->pulseid);
        $filteredusers = array_filter($users, function($user) use ($updatedusersid, $availabilities) {
            return (!in_array($user->id, $updatedusersid)
                && in_array($user->id, array_keys($availabilities))) ? true : false;
        });

        $instance->users = $filteredusers;

        return true;
    }

    /**
     * List of available custom user profile fields in text datatype.
     *
     * @return array
     */
    public static function userprofile_fields(): array {
        global $DB;

        if ($records = $DB->get_records('user_info_field', ['datatype' => 'text'])) {
            $fields = array_map(function($value) {
                return $value->name;
            }, $records);
            return $fields;
        }
        return [];
    }

    /**
     * Selected user profile field to store the credits score.
     *
     * @return array
     */
    public static function creditsfield(): array {
        global $DB;

        $field = get_config('pulseaddon_credits', 'creditsfield');
        $exists = $DB->get_record('user_info_field', ['id' => $field]);

        return ($exists) ? ['shortname' => 'profile_field_' . $exists->shortname, 'id' => $exists->id] : [];
    }

    /**
     * Recalculate the user credits and update the user profile field.
     *
     * @param int $userid
     * @return void
     */
    public static function recalculate_usercredits(int $userid) {
        global $DB;

        $self = (new self());
        $credits = $self->fetch_usermodcredits($userid);

        $condition = ['fieldid' => $self->profilefieldid, 'userid' => $userid];
        if ($DB->record_exists('user_info_data', $condition)) {
            $DB->set_field('user_info_data', 'data', $credits, $condition);
        } else {
            $DB->insert_records('user_info_data', $credits);
        }
    }

    /**
     * Recalculate credits for users enrolled in give course.
     *
     * @param int $courseid Course id.
     * @return void
     */
    public static function recalculate_pulsecredits(int $courseid) {
        global $DB;

        $coursecontext = \context_course::instance($courseid);
        $enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.*', null, 0, 0, true);
        $insertrecords = [];
        $self = (new self());
        foreach ($enrolledusers as $userid => $user) {
            $credits = $self->fetch_usermodcredits($user->id);
            $condition = ['fieldid' => $self->profilefieldid, 'userid' => $user->id];
            if ($DB->record_exists('user_info_data', $condition)) {
                $DB->set_field('user_info_data', 'data', $credits, $condition);
            } else {
                $insertrecords[] = ['userid' => $user->id, 'fieldid' => $self->profilefieldid, 'data' => $credits];
            }
        }
        if (!empty($insertrecords)) {
            $DB->insert_records('user_info_data', $insertrecords);
        }
    }
}
