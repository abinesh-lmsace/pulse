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
 * Credits addon instance class.
 *
 * @package    pulseaddon_credits
 * @copyright  2024 bdecent GmbH <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_credits;

use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Credits addon instance class handles credit related functionalities.
 */
class instance extends \mod_pulse\addon\base {

    /**
     * Get the name of the addon.
     *
     * @return string Name of the addon
     */
    public function get_name() {
        return 'credits';
    }

    /**
     * Add credit related form fields before invitation section.
     *
     * @param \MoodleQuickForm $mform The form object
     * @param object $instance The pulse instance
     * @return void
     */
    public static function form_fields_before_invitation(&$mform, $instance) {
        $mform->addElement('header', 'actions', get_string('actions', 'pulse'));

        if (empty(\pulseaddon_credits\credits::creditsfield())) {
            $setuppending = get_string("setupcredit", 'pulse');
            $setup = get_string('setup', 'pulse');
            $pulsesettings = new moodle_url('/admin/settings.php?section=pulseaddon_credits');
            $mform->addElement('html',
            '<div class="credits-field-pending">'.$setuppending.' <a href="'.$pulsesettings.'">'.$setup.'</a></div>');
        } else {
            $credit = $mform->createElement('text', 'options[credits]', get_string('credits', 'pulse'));
            $credits[] =& $credit;
            $credits[] =& $mform->createElement('advcheckbox', 'options[credits_status]', '', get_string('enable'));
            $mform->addGroup($credits, 'creditgroup', get_string('creditesgroup', 'pulse'), '', false);
            $mform->disabledIf('options[credits]', 'options[credits_status]');
            $mform->setType('options[credits]', PARAM_INT);
        }
    }

    /**
     * Extended the Pulse module add/update form validation method.
     *
     * @param \MoodleQuickForm $mform The form object
     * @param \stdClass $modform The module form object
     * @param array $data The form data
     * @param array $files The files data
     * @return array $errors List of errors.
     */
    public static function form_validation($mform, $modform, $data, $files) {
        $errors = [];
        if (isset($data['options']['credits_status']) && $data['options']['credits_status']) {
            if (empty($data['options']['credits'])) {
                $errors['creditgroup'] = get_string('required');
            } else if (!is_numeric($data['options']['credits'])) {
                $errors['creditgroup'] = get_string('numeric');
            }
        }
        return $errors;
    }

    /**
     * Event observer for user enrolment created event.
     *
     * This method is triggered when a user enrolment is created. It retrieves the
     * necessary pulse instances for the course and updates the user credits.
     *
     * @param \core\event\user_enrolment_created $event The event object containing event data.
     */
    public static function event_user_enrolment_created($event) {
        global $DB;

        // Implementation for event observer.
        $userid = $event->relateduserid; // Unenrolled user id.
        $user = \core_user::get_user($userid);
        $users = [$userid => $user];
        $courseid = $event->courseid;

        $sql = "SELECT p.id as pulseid, p.*, pp.value as credits_status, po.value as credits, cm.id as cmid
                FROM {pulse} p
                JOIN {pulse_options} po ON po.pulseid = p.id AND po.name = 'credit'
                JOIN {pulse_options} pp ON pp.pulseid = p.id AND pp.name = 'credits_status'
                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module= (
                    SELECT m.id FROM {modules} m WHERE m.name=:pulse
                )
                WHERE p.course=:courseid AND cm.visible = 1 AND pp.value = '1'";

        $instances = $DB->get_records_sql($sql, ['pulse' => 'pulse', 'courseid' => $courseid]);

        if (!empty($instances)) {
            foreach ($instances as $instance) {
                (new \pulseaddon_credits\credits())->update_usercredits($instance, $users, true);
            }
        }
    }

    /**
     * User unenrolled event observer.
     *
     * Remove the unenrolled user records related to list of pulse instances created in the course.
     * It deletes the users availability data, reaction tokens and activity completion data.
     *
     * @param  stdclass $event
     * @return bool true
     */
    public static function event_user_enrolment_deleted($event) {
        global $DB;
        // Implementation for event observer.
        $userid = $event->relateduserid; // Unenrolled user id.
        $courseid = $event->courseid;
        // Retrive list of pulse instance added in course.
        $list = \mod_pulse\helper::course_instancelist($courseid);
        if (!empty($list)) {
            $pulselist = array_column($list, 'instance');
            list($insql, $inparams) = $DB->get_in_or_equal($pulselist);
            $inparams[] = $userid;
            $select = " pulseid $insql AND userid = ? ";

            $DB->delete_records_select('pulseaddon_credits', $select, $inparams);
        }
        return true;
    }

    /**
     * course module deleted event observer.
     * Remove the user and instance records for the deleted modules from pulsepro tables.
     *
     * @param  stdclass $event
     * @return void
     */
    public static function event_course_module_deleted($event) {
        global $DB;

        if ($event->other['modulename'] == 'pulse') {
            $pulseid = $event->other['instanceid'];
            // Remove pulse user credits records.
            if ($DB->record_exists('pulseaddon_credits', ['pulseid' => $pulseid])) {
                $DB->delete_records('pulseaddon_credits', ['pulseid' => $pulseid]);
            }
        }
    }

}
