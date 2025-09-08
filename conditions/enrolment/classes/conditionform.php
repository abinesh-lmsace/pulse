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
 * Conditions - Pulse condition class for the "Enrolment Completion".
 *
 * @package   pulsecondition_enrolment
 * @copyright 2023, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace pulsecondition_enrolment;

use mod_pulse\automation\condition_base;

/**
 * Pulse automation conditions form and basic details.
 */
class conditionform extends \mod_pulse\automation\condition_base {

    /**
     * Verify the user is enroled in the course which is configured in the conditions for the notification.
     *
     * @param stdclass $instancedata
     * @param int $userid
     * @param \completion_info $completion
     * @return bool
     */
    public function is_user_completed($instancedata, int $userid, \completion_info $completion=null) {
        $courseid = $instancedata->courseid;
        $context = \context_course::instance($courseid);

        return is_enrolled($context, $userid);
    }

    /**
     * Include data to action.
     *
     * @param array $option
     * @return void
     */
    public function include_condition(&$option) {
        $option['enrolment'] = get_string('enrolment', 'pulsecondition_enrolment');
    }

    /**
     * Loads the form elements for activity condition in template.
     *
     * @param MoodleQuickForm $mform The form object.
     * @param object $forminstance The form instance.
     */
    public function load_template_form(&$mform, $forminstance) {
        global $PAGE;

        $completionstr = get_string('enrolment', 'pulsecondition_enrolment');
        $mform->addElement('select', 'condition[enrolment][status]', $completionstr, $this->get_options());
        $mform->addHelpButton('condition[enrolment][status]', 'enrolment', 'pulsecondition_enrolment');
    }

    /**
     * Loads the form elements for enrolment condition.
     *
     * @param MoodleQuickForm $mform The form object.
     * @param object $forminstance The form instance.
     */
    public function load_instance_form(&$mform, $forminstance) {

        $completionstr = get_string('enrolment', 'pulsecondition_enrolment');
        $mform->addElement('select', 'condition[enrolment][status]', $completionstr, $this->get_options());
        $mform->addHelpButton('condition[enrolment][status]', 'enrolment', 'pulsecondition_enrolment');
    }

    /**
     * User enrolled event observer. Triggeres the instance actions when user enrolled in the course.
     *
     * @param stdclass $eventdata
     * @return void
     */
    public static function user_enrolled($eventdata) {

        $data = $eventdata->get_data();
        $courseid = $data['courseid'];
        $relateduserid = $data['relateduserid'] ?: $data['userid'];

        self::trigger_user_enrolled($courseid, $relateduserid);
    }

    /**
     * Trigger the actions for the instances that are configured user enrolment.
     *
     * @param int $courseid Course id.
     * @param int $relateduserid User id.
     *
     * @return bool
     */
    protected static function trigger_user_enrolled(int $courseid, int $relateduserid) {
        global $DB;

        // Trigger the instances, this will trigger its related actions for this user.

        $sql = "SELECT *, ai.id as instanceid FROM {pulse_autoinstances} ai
        JOIN {pulse_autotemplates} pat ON pat.id = ai.templateid
        LEFT JOIN {pulse_condition_overrides} co ON co.instanceid = ai.id AND co.triggercondition = 'enrolment'
        WHERE ai.courseid=:courseid AND (co.status > 0 OR (co.status IS NULL AND ai.templateid IN (
                SELECT c.templateid FROM {pulse_condition} c WHERE c.triggercondition = 'enrolment'
            )
        ))";

        $params = ['courseid' => $courseid, 'value' => '%"enrolment"%', 'cstatus' => self::DISABLED];
        $instances = $DB->get_records_sql($sql, $params);

        foreach ($instances as $key => $instance) {
            $condition = (new self())->trigger_instance($instance->instanceid, $relateduserid, null, true);
        }
        return true;
    }

    /**
     * User enrolled event observer. Triggeres the instance actions when user enrolled in the course.
     *
     * @param stdclass $event
     * @return void
     */
    public static function user_enrolment_created($event) {
        $userid = $event->relateduserid;
        self::set_recently_enrolled_userid($userid, true);
    }

    /**
     * Maintain the recently enrolled user to verify the role assignment.
     *
     * @param int $userid Newely created userid.
     * @param bool $clear Clear the static user id.
     * @return int
     */
    protected static function set_recently_enrolled_userid(int $userid, $clear=false) {
        static $enrolleduser;

        $previoususer = 0;
        if (is_null($enrolleduser) || $clear) {
            $previoususer = $enrolleduser;
            $enrolleduser = $userid;
        }

        return $previoususer;
    }

    /**
     * User assigned in the role.
     *
     * Verify the event related user is enrolled in the course recently, with recent stored user.
     * Trigger the schedule instance if recent enrolled user.
     *
     * @param stdclass $event
     * @return void
     */
    public static function user_role_assigned($event) {

        $lastenrolleduser = self::set_recently_enrolled_userid(0, true);

        if ($lastenrolleduser && $lastenrolleduser == $event->relateduserid) {
            $courseid = $event->get_context()->instanceid;
            self::trigger_user_enrolled($courseid, $lastenrolleduser);
        }

    }
}
