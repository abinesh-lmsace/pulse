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
 * This file contains the notification class for the pulse reminder addon.
 *
 * @package   pulseaddon_reminder
 * @copyright 2024 bdecent GmbH <https://bdecent.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_reminder;

defined('MOODLE_INTERNAL') || die('No direct access !');

require_once($CFG->dirroot . '/mod/pulse/automation/automationlib.php');

use mod_pulse\automation\helper;
use mod_pulse_context_module;
use pulseaddon_reminder\task\sendreminders;
use stdclass;

require_once($CFG->dirroot.'/mod/pulse/lib.php');

/**
 * Send reminder notification to users filter by the users availability.
 */
class notification extends \mod_pulse\addon\notification {

    /**
     * Fetched complete record for all instances.
     *
     * @var array
     */
    private $records;

    /**
     * Module info sorted by course.
     *
     * @var mod_info|array
     */
    public $modinfo = [];

    /**
     * List of created pulse instances in LMS.
     *
     * @var array
     */
    protected $instances;

    /**
     * Pulse instance data record.
     *
     * @var object
     */
    public $instance;

    /**
     * Fetch all pulse instance data with course and context data.
     * Each instance are set to adhoc task to send reminders.
     *
     * @param  string $additionalwhere Additional where condition to filter the pulse record
     * @param  array $additionalparams Parameters for additional where clause.
     * @return array
     */
    public function get_instances($additionalwhere='', $additionalparams=[]) {
        global $DB;

        $select[] = 'pl.id AS id'; // Set the schdule id as unique column.

        // Get columns not increase table queries.
        // ...TODO: Fetch only used columns. Fetching all the fields in a query will make double the time of query result.
        $tables = [
            'pl' => $DB->get_columns('pulse'),
            'c' => $DB->get_columns('course'),
            'ctx' => $DB->get_columns('context'),
            'cm' => array_fill_keys(['id', 'course', 'module', 'instance'], ""), // Make the values as keys.
            'md' => array_fill_keys(['id', 'name'], ""),
            're' => $DB->get_columns('pulseaddon_reminder'),
        ];

        foreach ($tables as $prefix => $table) {
            $columns = array_keys($table);
            // Columns.
            array_walk($columns, function(&$value, $key, $prefix) {
                $value = "$prefix.$value AS ".$prefix."_$value";
            }, $prefix);

            $select = array_merge($select, $columns);
        }

        // Number of notification to send in this que.
        $limit = get_config('pulse', 'schedulecount') ?: 100;

        // Final list of select columns, convert to sql mode.
        $select = implode(', ', $select);

        $sql = "SELECT $select
                FROM {pulse} pl
                JOIN {pulseaddon_reminder} re ON re.pulseid = pl.id
                JOIN {course} c ON c.id = pl.course
                JOIN {course_modules} cm ON cm.instance = pl.id
                JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                JOIN {modules} md ON md.id = cm.module
                WHERE md.name = 'pulse' AND cm.visible = 1 AND c.visible = 1
                AND c.startdate <= :startdate AND (c.enddate = 0 OR c.enddate >= :enddate)";

        $sql .= $additionalwhere ? ' AND '.$additionalwhere : '';

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'startdate' => time(),
            'enddate' => time(),
        ];
        $params = array_merge($params, $additionalparams);
        $this->records = $DB->get_records_sql($sql, $params);

        if (empty($this->records)) {
            pulse_mtrace('No pulse instance are added yet'."\n");
            return false;
        }

        pulse_mtrace('Fetched available pulse modules');

        foreach ($this->records as $record) {

            $instance = new stdclass();
            $instance->pulse = (object) helper::filter_record_byprefix($record, 'pl');
            $instance->reminder = (object) helper::filter_record_byprefix($record, 're');
            $instance->course = (object) helper::filter_record_byprefix($record, 'c');
            $instance->context = (object) helper::filter_record_byprefix($record, 'ctx');
            $cm = (object) helper::filter_record_byprefix($record, 'cm');
            $instance->module = (object) helper::filter_record_byprefix($record, 'md');
            $instance->record = $record;

            if (!in_array($instance->course->id, $this->modinfo)) {
                $this->modinfo[$instance->course->id] = get_fast_modinfo($instance->course->id, 0);
            }
            $instance->modinfo = $this->modinfo[$instance->course->id];

            if (!empty($cm->id) && !empty($this->modinfo[$instance->course->id]->cms[$cm->id])) {
                $instance->cmdata = $cm;
                $instance->cm = $instance->modinfo->get_cm($cm->id);
                // Fetch list of sender users for the instance.
                $instance->sender = \mod_pulse\task\sendinvitation::get_sender($instance->course->id);

                $this->instances[$instance->pulse->id] = $instance;
            }

        }

        return $this->instances;
    }

    /**
     * Set the instance property.
     *
     * @param stdclass $instance The instance to set.
     * @return void
     */
    public function set_instance(stdclass $instance) {
        $this->instance = $instance;
    }

    /**
     * Fetch and merge the user availability fields data into user instance data.
     *
     * @param  array $users
     * @param  int $pulseid
     * @return void
     */
    public function merge_user_availability(&$users, $pulseid) {
        global $DB;

        $availablityfields = $this->availability_fields();
        $userids = array_keys($users);
        if (!empty($userids)) {
            list($inusersql, $inuserparams) = $DB->get_in_or_equal($userids);

            $availablesql = "SELECT pp.id, pp.userid, pp.status as isavailable
                            FROM {pulseaddon_availability} pp
                            WHERE pp.userid $inusersql AND pp.pulseid = ?";

            $inuserparams[] = $pulseid;
            $availabilityusers = $DB->get_records_sql($availablesql, $inuserparams);

            foreach ($users as $key => $value) {
                if (isset($availabilityusers[$value->id])) {
                    $userdata = (array) $availabilityusers[$value->id];
                    $users[$value->id] = (object) array_merge((array) $value, $userdata);
                } else {
                    // Add not updated teacher elements to the availability fields - Quick FIX - PST.
                    foreach (explode(',', $availablityfields) as $field) {
                        $fieldname = trim(str_replace('pp.', '', $field));
                        $users[$value->id]->{$fieldname} = '';
                    }
                    $users[$value->id]->isavailable = 0;
                }
            }
        }
    }

    /**
     * Get the student users for the given instance and type.
     *
     * @param stdclass $instance The instance to get the student users for.
     * @param string $type The type of reminder.
     * @param bool $inrole Whether to include users in the role.
     * @param bool $verifyforuser Whether to verify for a specific user.
     * @param int $groupids The group IDs to filter by.
     * @param string $verifycondition Additional verification condition.
     * @param array $verifyconditionparams Parameters for the verification condition.
     * @return array|bool The list of student users or false if the instance is empty.
     */
    public function get_student_users($instance, $type, $inrole=true, $verifyforuser=false, $groupids=0,
        $verifycondition='', $verifyconditionparams=[]) {

        global $DB;

        if (empty($instance)) {
            return false;
        }

        if ($type == 'invitation') {
            return $this->get_invitation_student_users($instance, $type, $inrole, $verifyforuser,
                $groupids, $verifycondition, $verifyconditionparams);
        }

        $instance->type = $type;
        $this->instance = $instance;

        $limit = get_config('mod_pulse', 'tasklimituser') ?: 100;
        // ...TODO: USE context data form DB.
        $context = \context_module::instance($this->instance->context->instanceid);

        $cap = 'mod/pulse:notifyuser';

        $additionalwhere[] = 'u.id IN (
            SELECT userid FROM {pulseaddon_availability} WHERE pulseid = :pulseidavail AND status = 1
        )';

        $additionalparams = ['pulseidavail' => $this->instance->pulse->id, 'type' => $type];

        $recurringverify = '';
        if ($type == 'recurring') {
            $duration = isset($instance->reminder->{$type.'_relativedate'}) ? $instance->reminder->{$type.'_relativedate'} : 0;
            $currenttime = time();
            $remindertime = $currenttime - $duration;

            $recurringverify = ' AND reminder_time > :recur_remindertime';
            $additionalparams += ['recur_remindertime' => $remindertime];
        }

        // For any other roles like course and user context.
        if ($verifyforuser) {

            $additionalwhere[] = 'u.id NOT IN (
                SELECT foruserid
                FROM {pulseaddon_reminder_notified}
                WHERE pulseid = :pulseidremain AND reminder_status = 1 AND reminder_type = :type AND userid = :verifyuser
                '. $recurringverify .'
            )';

            $additionalparams += ['verifyuser' => $verifyforuser ,  'pulseidremain' => $this->instance->pulse->id];

            if ($verifycondition) {
                $additionalwhere[] = $verifycondition;
                $additionalparams += $verifyconditionparams;
            }

        } else {

            $additionalwhere[] = 'u.id NOT IN (
                SELECT userid
                FROM {pulseaddon_reminder_notified}
                WHERE pulseid = :pulseidremain AND reminder_status = 1 AND reminder_type = :type
                '. $recurringverify .'
            )';

            $additionalparams += ['pulseidremain' => $this->instance->pulse->id];
        }

        if ($inrole) {
            list($roleinsql, $roleparams) = $DB->get_in_or_equal(
                explode(',', $this->instance->reminder->{$type.'_recipients'}), SQL_PARAMS_NAMED, 'rol');

            // Get enrolled users with capability.
            $contextlevel = explode('/', $this->instance->context->path);
            list($insql, $inparams) = $DB->get_in_or_equal(array_filter($contextlevel), SQL_PARAMS_NAMED, 'ins');

            $additionalwhere[] = "u.id IN (
                SELECT ra.userid
                FROM {role_assignments} ra
                JOIN {role} rle ON rle.id = ra.roleid
                WHERE contextid $insql AND ra.roleid $roleinsql
            )";

            $additionalparams += $roleparams;
            $additionalparams += $inparams;
        }

        $joins[] = 'JOIN {pulseaddon_availability} pla ON pla.userid = u.id AND pla.pulseid = :pulseidavail2';
        $joins[] = '
            LEFT JOIN (
                SELECT userid, pulseid,
                MAX(reminder_time) as ' . $type . '_reminder_time, MAX(reminder_type) as reminder_type
                FROM {pulseaddon_reminder_notified}
                GROUP BY userid, pulseid
                ORDER BY MAX(id) DESC
            ) prn ON prn.userid = u.id AND prn.pulseid = pla.pulseid AND prn.reminder_type = :rmtype';

        $joinparams = ['pulseidavail2' => $this->instance->pulse->id, 'rmtype' => $type];

        // Include the filter by reminder time.
        // Filter the students by the reminder time if the reminder is set as scheduled or recurring.
        if ($type == 'invitation') {
            $filteravailability = false; // No need to filter the students for invitation.
        } else {
            $filteravailability = ($type == 'recurring'
            || (isset($instance->reminder->{$type.'_schedule'}) && $instance->reminder->{$type.'_schedule'} == 1)) ? true : false;
        }

        // Filter student users. modules availabilty test returns false for other roles like teachers.
        // So need to filter the students from userslist before check the modules availability.
        if ($filteravailability) {
            $duration = isset($instance->reminder->{$type.'_relativedate'}) ? $instance->reminder->{$type.'_relativedate'} : 0;
            $currenttime = time();
            $remindertime = $currenttime - $duration;

            // Add the reminder time condition to the query.
            $additionalwhere[] = 'pla.availabletime <= :remindertime';
            $additionalparams['remindertime'] = $remindertime;
        }

        $users = \mod_pulse\addon\util::get_enrolled_users_sql(
            $context, $cap, $groupids, 'u.*, pla.availabletime, pla.status as isavailable, prn.* ', 'u.lastname, u.firstname',
            0, $limit, true, $additionalwhere, $additionalparams, $joins, $joinparams);

        return $users;
    }

    /**
     * Get the invitation student users for the given instance and type.
     *
     * @param stdclass $instance The instance to get the student users for.
     * @param string $type The type of reminder.
     * @param bool $inrole Whether to include users in the role.
     * @param bool $verifyforuser Whether to verify for a specific user.
     * @param int $groupids The group IDs to filter by.
     * @param string $verifycondition Additional verification condition.
     * @param array $verifyconditionparams Parameters for the verification condition.
     * @return array|bool The list of student users or false if the instance is empty.
     */
    public function get_invitation_student_users($instance, $type, $inrole=true, $verifyforuser=false,
        $groupids=0, $verifycondition='', $verifyconditionparams=[]) {

        global $DB;

        if (empty($instance)) {
            return false;
        }

        $instance->type = $type;
        $this->instance = $instance;

        $limit = get_config('mod_pulse', 'tasklimituser') ?: 100;
        $context = \context_module::instance($this->instance->context->instanceid);

        $cap = 'mod/pulse:notifyuser';

        $additionalwhere[] = 'u.id IN (
            SELECT userid FROM {pulseaddon_availability} WHERE pulseid = :pulseidavail AND status = 1
        )';

        $additionalparams = ['pulseidavail' => $this->instance->pulse->id, 'type' => $type];

        if ($verifyforuser) {
            $additionalwhere[] = 'u.id NOT IN (
                SELECT foruserid
                FROM {pulseaddon_reminder_notified}
                WHERE pulseid = :pulseidremain AND reminder_status = 1 AND reminder_type = :type AND userid = :verifyuser
            )';

            $additionalparams += ['verifyuser' => $verifyforuser ,  'pulseidremain' => $this->instance->pulse->id];

            if ($verifycondition) {
                $additionalwhere[] = $verifycondition;
                $additionalparams += $verifyconditionparams;
            }

        } else {
            $additionalwhere[] = 'u.id NOT IN (
                SELECT userid
                FROM {pulse_users}
                WHERE pulseid = :pulseidremain AND status = 1
            )';

            $additionalparams += ['pulseidremain' => $this->instance->pulse->id];
        }

        if ($inrole) {
            list($roleinsql, $roleparams) = $DB->get_in_or_equal(
                explode(',', $this->instance->reminder->{$type.'_recipients'}), SQL_PARAMS_NAMED, 'rol');

            $contextlevel = explode('/', $this->instance->context->path);
            list($insql, $inparams) = $DB->get_in_or_equal(array_filter($contextlevel), SQL_PARAMS_NAMED, 'ins');

            $additionalwhere[] = "u.id IN (
                SELECT ra.userid
                FROM {role_assignments} ra
                JOIN {role} rle ON rle.id = ra.roleid
                WHERE contextid $insql AND ra.roleid $roleinsql
            )";

            $additionalparams += $roleparams;
            $additionalparams += $inparams;
        }

        $joins[] = 'JOIN {pulseaddon_availability} pla ON pla.userid = u.id AND pla.pulseid = :pulseidavail2';

        $joinparams = ['pulseidavail2' => $this->instance->pulse->id, 'rmtype' => $type];

        $users = \mod_pulse\addon\util::get_enrolled_users_sql(
            $context, $cap, $groupids, 'u.*, pla.availabletime, pla.status as isavailable', 'u.lastname, u.firstname',
            0, $limit, true, $additionalwhere, $additionalparams, $joins, $joinparams);

        return $users;
    }

    /**
     * Get the teacher users for the given instance and type.
     *
     * @param stdclass $instance The instance to get the teacher users for.
     * @param string $type The type of reminder.
     * @return array|bool The list of teacher users or false if the instance is empty.
     */
    public function get_teacher_users($instance, $type) {
        global $DB;

        // Consider the users in course context roles without notify user capability as teacher users.
        // Teachers are get the reminders for each of their students.

        // Get enrolled users with capability.
        // Define the roles and context.
        if (empty($instance)) {
            return false;
        }

        $instance->type = $type;
        $this->instance = $instance;

        $pulseroles = explode(',', $instance->reminder->{$type.'_recipients'});
        $contextlevel = explode('/', $instance->context->path);

        // Prepare SQL parameters.
        list($roleinsql, $roleparams) = $DB->get_in_or_equal($pulseroles, SQL_PARAMS_NAMED, 'rol');
        list($insql, $inparams) = $DB->get_in_or_equal(array_filter($contextlevel), SQL_PARAMS_NAMED, 'ins');
        list($insql2, $inparams2) = $DB->get_in_or_equal(array_filter($contextlevel), SQL_PARAMS_NAMED, 'ins2');

        // SQL query to fetch users in the specified roles without the pulse:notifyuser capability.
        $sql = "
            SELECT u.*
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {role} r ON r.id = ra.roleid
            JOIN {context} ctx ON ctx.id = ra.contextid
            LEFT JOIN (
                SELECT ra.userid
                FROM {role_assignments} ra
                JOIN {role_capabilities} rc ON rc.roleid = ra.roleid
                WHERE rc.capability = :capability
                AND rc.permission = 1
                AND ra.contextid $insql
            ) notifyusers ON notifyusers.userid = u.id
            WHERE ra.roleid $roleinsql
            AND ctx.id $insql2
            AND notifyusers.userid IS NULL
            AND u.deleted = 0 AND u.suspended = 0
        ";

        $teachers = $DB->get_records_sql($sql, $roleparams + $inparams + $inparams2 + ['capability' => 'mod/pulse:notifyuser']);

        if (!empty($teachers)) {
            $this->filter_group_users($teachers);
        }

        return $teachers;
    }

    /**
     * Get the parent users for the given instance and type.
     *
     * @param stdclass $instance The instance to get the parent users for.
     * @param string $type The type of reminder.
     * @return array|bool The list of parent users or false if the instance is empty.
     */
    public function get_parent_users($instance, $type) {
        global $DB;

        // Get enrolled users with capability.
        // Define the roles and context.
        if (empty($instance)) {
            return false;
        }

        $instance->type = $type;
        $this->instance = $instance;

        $pulseroles = explode(',', $instance->reminder->{$type.'_recipients'});
        $context = mod_pulse_context_module::create_instance_fromrecord($instance->context);

        // Prepare SQL parameters.
        list($roleinsql, $roleparams) = $DB->get_in_or_equal($pulseroles, SQL_PARAMS_NAMED, 'rol');
        list($esql, $params) = get_enrolled_sql($context, 'mod/pulse:notifyuser', 0, true);
        $sql = "SELECT u.*
                FROM {user} u WHERE u.deleted = 0 AND u.suspended = 0 AND u.id IN (
                    SELECT DISTINCT ra.userid
                    FROM {user} u
                    JOIN ($esql) je ON je.id = u.id
                    JOIN {context} ctx ON ctx.instanceid = u.id AND ctx.contextlevel = :contextlevel
                    JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.roleid $roleinsql
                )";

        // Params for the sql query.
        $params = ['contextlevel' => CONTEXT_USER] + $params + $roleparams;

        // Execute the query to fetch the parent users.
        $users = $DB->get_records_sql($sql, $params);

        if (!empty($users)) {
            foreach ($users as &$user) {
                $verification = '
                    u.id IN (
                        SELECT instanceid
                        FROM {context} ctx
                        JOIN {role_assignments} ra ON ra.contextid = ctx.id
                        WHERE ra.userid = :parentuserid AND ra.roleid ' . $roleinsql .'
                    )';
                $params = ['parentuserid' => $user->id] + $roleparams;
                $students = $this->get_student_users($instance, $type, false, $user->id, 0, $verification, $params);

                $user->students = $students;
            }
        }

        return $users;
    }

    /**
     * Filter students based on their availability for the instance.
     * Users are filtered by the time difference selected for the reminder and the user module available time.
     * Filter by time only true when the reminder option set as relative time.
     *
     * @param  array $users List of enrolled users in course.
     * @param  stdclass $instance Pulse instance data.
     * @param  int|null $duration Selected reminder duration.
     * @return array List of available users.
     */
    public function filter_students_byremindertime($users, $instance, $duration=null) {

        $users = array_filter($users, function($value) use ($instance, $duration) {

            if ($instance->type == 'recurring') {
                // Send the recurring reminders in selected duration time intervals.
                $availabletime = !empty($value->availabletime) ? $value->availabletime : time();
                $comparetime = ($value->recurring_reminder_time != '') ? $value->recurring_reminder_time : $availabletime;
                $difference = time() - $comparetime;
                if ($duration && $difference > $duration) {
                    return true;
                }
            } else {
                // Filter first and second reminders difference between the selected duration and users module available time.
                $availabletime = !empty($value->availabletime) ? $value->availabletime : time();
                $difference = time() - $availabletime;
                if ($duration && $difference > $duration) {
                    return true;
                }
            }
            return false;

        });

        return $users;
    }

    /**
     * Generate users list with data who are available to receive the reminder notifications.
     * Selected recipients role users are filtered by fixed/relative date, by role context level.
     *
     * User context role users and course context users other than students are stored separately and notified separately.
     *
     * @param  int $pulseid Pulse instance id.
     * @param  stdClass $instance Pulse instance data.
     * @param  string $type Type of reminder (first, second, recurring, invitation).
     * @param array $excludeusers List of users id need to exclude from result.
     * @return array separated list of users by role.
     */
    public function generate_users_data($pulseid, $instance, $type, $excludeusers=[]) {
        global $DB;

        $recipients = explode(',', $instance->reminder->{$type.'_recipients'});
        list($roleinsql, $roleinparams) = $DB->get_in_or_equal($recipients);

        // Student role.
        $rolesql = "SELECT rc.id, rc.roleid
                    FROM {role_capabilities} rc
                    JOIN {capabilities} cap ON rc.capability = cap.name
                    JOIN {context} ctx on rc.contextid = ctx.id
                    WHERE rc.capability = :capability ";

        $roles = $DB->get_records_sql($rolesql, ['capability' => 'mod/pulse:notifyuser']);
        $roles = array_column($roles, 'roleid'); // Notify user roles.
        list($sturoleinsql, $sturoleinparams) = $DB->get_in_or_equal($roles);

        // Get available users in course.
        // Get enrolled users with capability.
        $contextlevel = explode('/', $instance->context['path']);
        list($insql, $inparams) = $DB->get_in_or_equal(array_filter($contextlevel));

        $usersql = "SELECT u.*, je.roleshortname, je.roleid, je.archetype, pu.timecreated as invitation_reminder_time
            FROM {user} u
            LEFT JOIN {pulse_users} pu ON (pu.status = 1 AND pu.userid = u.id AND pu.pulseid = ?)
            JOIN (SELECT DISTINCT eu1_u.id, ra.roleshortname, ra.roleid, ra.archetype
                FROM {user} eu1_u
                JOIN {user_enrolments} ej1_ue ON ej1_ue.userid = eu1_u.id
                JOIN {enrol} ej1_e ON (ej1_e.id = ej1_ue.enrolid AND ej1_e.courseid = ?)
                JOIN (SELECT userid, Max(rle.shortname) as roleshortname, MAX(roleid) as roleid, rle.archetype
                        FROM {role_assignments}
                        JOIN {role} rle ON rle.id = roleid
                        WHERE contextid $insql
                        AND ( roleid $roleinsql OR roleid $sturoleinsql ) GROUP BY userid
                    ) ra ON ra.userid = eu1_u.id
                WHERE ej1_ue.status = 0
                AND (ej1_ue.timestart = 0 OR ej1_ue.timestart <= ?)
                AND (ej1_ue.timeend = 0 OR ej1_ue.timeend > ?)
                AND eu1_u.deleted = 0 AND eu1_u.id <> ? AND eu1_u.suspended = 0
                ) je ON je.id = u.id
        WHERE u.deleted = 0 AND u.suspended = 0 ";

        $params[] = $pulseid;
        $params[] = $instance->course['id'];
        $params = array_merge($params, array_filter($inparams));
        $params = array_merge($params, array_filter($roleinparams));
        $params = array_merge($params, array_filter($sturoleinparams));
        $params[] = time();
        $params[] = time();
        $params[] = 1;

        if (!empty($excludeusers)) {
            list($insql, $param) = $DB->get_in_or_equal($excludeusers, SQL_PARAMS_QM, '', false);
            $usersql .= ' AND u.id '.$insql;
            $params = array_merge($params, array_values($param));
        }

        $usersql .= " ORDER BY u.lastname, u.firstname, u.id ";

        $users = $DB->get_records_sql($usersql, $params);

        $this->merge_user_availability($users, $pulseid);
        // Add the filter for relative date for reminder.
        if ($type == 'invitation') {
            $filteravailability = false;
        } else {
            $filteravailability = ($type == 'recurring'
            || (isset($instance->reminder->{$type.'_schedule'}) && $instance->reminder->{$type.'_schedule'} == 1)) ? true : false;
        }
        // Filter student users. modules availabilty test returns false for other roles like teachers.
        // So need to filter the students from userslist before check the modules availability.
        $duration = isset($instance->reminder->{$type.'_relativedate'}) ? $instance->reminder->{$type.'_relativedate'} : 0;

        $instance->type = $type;
        $students = $this->filter_students($users, $instance, $filteravailability, $duration);
        // Filter the users who has access to this instance.

        // Fetch parent users in context role.
        $parents = $this->get_parent_users($recipients, $students, $instance->course['id'], $pulseid);
        // Filter other selected roles.
        $teachers = array_filter($users, function($value) {
            return ($value->archetype != 'student') ? true : false;
        });

        if (!empty($teachers)) {
            $this->filter_group_users($teachers, $students);
        }
        // Remove the students list if the reminder doesn't select the student role to get recipients.
        if (!empty($students)) {
            $students = array_filter($students, function($student) use ($recipients) {
                return in_array($student->roleid, $recipients);
            });
        }
        return [$students, $parents, $teachers];
    }

    /**
     * Filter the users assigned in the groups. Find and separate the teachers and students based on the group.
     * So the teachers are prevent to receive reminders for other group students.
     * Returns the teachers list with the group students
     *
     * @param  array $teachers List of teachers
     * @return array $teachers List of teachers with list of students.
     */
    public function filter_group_users($teachers) {

        $count = 0;
        $limit = get_config('mod_pulse', 'tasklimituser') ?: 100;

        foreach ($teachers as $teacherid => $teacher) {

            // This is to prevent the memory issue for large number of users.
            if ($count >= $limit) {
                $teachers[$teacherid]->students = [];
                continue;
            }

            $canaccessallgroups = (has_capability('moodle/site:accessallgroups',
                \context_course::instance($this->instance->course->id), $teacher->id
            ));
            $forcegroups = ($this->instance->course->groupmode == SEPARATEGROUPS && !$canaccessallgroups);
            if ($forcegroups) {
                // Teacher user has only able to view the group users.
                $allowedgroupids = array_keys(groups_get_all_groups($this->instance->course->id, $teacher->id));
                $students = $this->get_student_users(
                    $this->instance, $this->instance->type, false, $teacher->id, $allowedgroupids);
                $teachers[$teacherid]->students = $students;

            } else {
                $students = $this->get_student_users($this->instance, $this->instance->type, false, $teacher->id, 0);
                $teachers[$teacherid]->students = $students;
            }

            $count += count($teachers[$teacherid]->students);
        }

        return $teachers;
    }

    /**
     * Setup the first reminder adhoc task for selected roles.
     * Users are filtered based on their time duration and module visibilty.
     *
     * @return void
     */
    public function first_reminder() {
        global $DB;
        // Get list of first remainders added.

        $instances = $this->get_instances();

        if (!empty($instances)) {

            foreach ($instances as $pulseid => $instance) {

                $reminder = false;
                // Selected roles for the reminder recipents.
                if (!$instance->reminder->first_reminder  || !$instance->reminder->first_recipients) {
                    continue;
                }

                pulse_mtrace('Start the First reminder for instance - '. $instance->pulse->name);

                // Check is fixed date expires.
                if ($instance->reminder->first_schedule == 0) {
                    pulse_mtrace('Fixed date scheduled');
                    $fixeddate = $instance->reminder->first_fixeddate;
                    if ($fixeddate < time()) {
                        $reminder = true;
                    }

                } else {
                    pulse_mtrace('Relative date scheduled');
                    $reminder = true;
                }

                if ($reminder) {
                    pulse_mtrace('Sending first reminder to students');
                    sendreminders::set_reminder_adhoctask($instance, 'first');

                    // Check if the pulse instance has user context roles selected.
                    if (!empty($instance->reminder->first_recipients)
                        && $this->has_user_context_roles($instance->reminder->first_recipients)) {

                        pulse_mtrace('Sending first reminder to user context roles');
                        sendreminders::set_reminder_adhoctask($instance, 'first', 'usercontext');
                    }

                    pulse_mtrace('Sending first reminder to teachers');
                    sendreminders::set_reminder_adhoctask($instance, 'first', 'coursecontext');
                }

            }
        }
    }

    /**
     * Check if the recipients include user context roles.
     *
     * @param string $recipients Comma-separated list of role IDs.
     * @return bool True if user context roles are included, false otherwise.
     */
    private function has_user_context_roles($recipients) {
        global $DB;

        $roleids = explode(',', $recipients);
        if (empty($roleids)) {
            return false;
        }

        list($roleinsql, $roleparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'rol');

        $sql = "
            SELECT r.id
            FROM {role} r
            JOIN {context} ctx ON ctx.instanceid = r.id
            WHERE r.id $roleinsql
            AND ctx.contextlevel = :contextlevel
        ";

        $params = array_merge($roleparams, ['contextlevel' => CONTEXT_USER]);

        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Setup the second reminder adhoc task for selected roles.
     * Users are filtered based on their time duration and module visibility.
     *
     * @return void
     */
    public function second_reminder() {

        $instances = $this->get_instances();

        if (!empty($instances)) {

            foreach ($instances as $pulseid => $instance) {

                $reminder = false;
                pulse_mtrace('Start the second reminder for instance - '. $instance->pulse->name);

                // Selected roles for the reminder recipents.
                if (!$instance->reminder->second_reminder || !$instance->reminder->second_recipients) {
                    continue;
                }

                // Check is fixed date expires.
                if ($instance->reminder->second_schedule == 0) {
                    pulse_mtrace('Fixed date scheduled');
                    $fixeddate = $instance->reminder->second_fixeddate;
                    if ($fixeddate < time()) {
                        $reminder = true;
                    }
                } else {
                    pulse_mtrace('Relative date scheduled');
                    $reminder = true;
                }

                if ($reminder) {
                    pulse_mtrace('Sending second reminder to students');
                    sendreminders::set_reminder_adhoctask($instance, 'second');

                    // Pulse second reminder.
                    if (!empty($instance->reminder->second_recipients)
                        && $this->has_user_context_roles($instance->reminder->second_recipients)) {

                        pulse_mtrace('Sending second reminder to user context roles');
                        sendreminders::set_reminder_adhoctask($instance, 'second', 'usercontext');
                    }

                    pulse_mtrace('Sending second reminder to teachers');
                    sendreminders::set_reminder_adhoctask($instance, 'second', 'coursecontext');
                }
            }
        }
    }

    /**
     * Setup the recurring reminder adhoc task for selected roles.
     * Users are filtered based on their time duration and module visibilty.
     *
     * @return void
     */
    public function recurring_reminder() {

        $instances = $this->get_instances();

        if (!empty($instances)) {

            foreach ($instances as $pulseid => $instance) {

                $reminder = true;
                pulse_mtrace('Start the recurring reminder for instance - '. $instance->pulse->name);

                // Selected roles for the reminder recipents.
                if (!$instance->reminder->recurring_reminder || $instance->reminder->recurring_recipients == '') {
                    continue;
                }

                pulse_mtrace('Relative date scheduled');

                if ($reminder) {
                    pulse_mtrace('Sending recurring reminder to students');
                    sendreminders::set_reminder_adhoctask($instance, 'recurring');

                    if (!empty($instance->reminder->recurring_recipients)
                        && $this->has_user_context_roles($instance->reminder->recurring_recipients)) {

                        pulse_mtrace('Sending recurring reminder to parents');
                        sendreminders::set_reminder_adhoctask($instance, 'recurring', 'usercontext');
                    }

                    pulse_mtrace('Sending recurring reminder to teachers');
                    sendreminders::set_reminder_adhoctask($instance, 'recurring', 'coursecontext');
                }

            }
        }
    }

    /**
     * Setup the invitation reminder adhoc task for selected roles.
     * Users are filtered based on their module visibilty.
     *
     * @return void
     */
    public function send_invitations() {
        global $DB;

        $instances = $this->get_instances("pl.pulse=:enabled AND re.invitation_recipients != ''", ['enabled' => 1]);

        if (!empty($instances) && (is_array($instances) || is_object($instances))) {

            foreach ($instances as $pulseid => $instance) {

                // Selected roles for the reminder recipents.
                if (!$instance->pulse || !$instance->reminder->invitation_recipients) {
                    continue;
                }

                pulse_mtrace('Start sending invitation for instance - '. $instance->pulse->name);

                pulse_mtrace('Sending invitation to students');
                sendreminders::set_reminder_adhoctask($instance, 'invitation');

                if (!empty($instance->reminder->invitation_recipients)
                    && $this->has_user_context_roles($instance->reminder->invitation_recipients)) {

                    pulse_mtrace('Sending invitation to parents');
                    sendreminders::set_reminder_adhoctask($instance, 'invitation', 'usercontext');
                }
                pulse_mtrace('Sending invitation to teachers');
                sendreminders::set_reminder_adhoctask($instance, 'invitation', 'coursecontext');
            }
        }
    }

    /**
     * Pulse pro availability table fields are defined to use in sql select query.
     *
     * @return string
     */
    public function availability_fields() {
        $fields = [
            'pp.userid', 'pp.pulseid', 'pp.availabletime',
            'pp.first_reminder_status', 'pp.second_reminder_status', 'pp.recurring_reminder_prevtime',
            'pp.first_reminder_time', 'pp.second_reminder_time', 'pp.recurring_reminder_time',
            'pp.invitation_users', 'pp.first_users', 'pp.second_users', 'pp.recurring_users',
        ];
        return implode(', ', $fields);
    }

}
