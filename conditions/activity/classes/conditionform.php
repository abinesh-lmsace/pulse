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
 * Conditions - Pulse condition class for the "Activity Completion".
 *
 * @package   pulsecondition_activity
 * @copyright 2023, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulsecondition_activity;

use mod_pulse\automation\condition_base;

/**
 * Automation condition form for acitivty completion.
 */
class conditionform extends \mod_pulse\automation\condition_base {

    /**
     * Include condition
     *
     * @param array $option
     * @return void
     */
    public function include_condition(&$option) {
        $option['activity'] = get_string('activitycompletion', 'pulsecondition_activity');
    }

    /**
     * Loads the form elements for activity condition.
     *
     * @param MoodleQuickForm $mform The form object.
     * @param object $forminstance The form instance.
     */
    public function load_instance_form(&$mform, $forminstance) {

        $completionstr = get_string('activitycompletion', 'pulsecondition_activity');
        $mform->addElement('select', 'condition[activity][status]', $completionstr, $this->get_options());
        $mform->addHelpButton('condition[activity][status]', 'activitycompletion', 'pulsecondition_activity');
        $courseid = $forminstance->get_customdata('courseid') ?? '';

        // Include the suppress activity settings for the instance.
        $completion = new \completion_info(get_course($courseid));
        $activities = $completion->get_activities();
        array_walk($activities, function(&$value) {
            $value = format_string($value->name);
        });

        $modules = $mform->addElement('autocomplete', 'condition[activity][modules]',
                            get_string('selectactivity', 'pulsecondition_activity'), $activities);
        $modules->setMultiple(true);
        $mform->hideIf('condition[activity][modules]', 'condition[activity][status]', 'eq', self::DISABLED);
        $mform->addHelpButton('condition[activity][modules]', 'selectactivity', 'pulsecondition_activity');

        // Enable the override by default to prevent adding overdide checkbox.
        $mform->addElement('hidden', 'override[condition_activity_modules]', 1);
        $mform->setType('override[condition_activity_modules]', PARAM_RAW);
    }

    /**
     * Loads the form elements for activity condition in template.
     *
     * @param MoodleQuickForm $mform The form object.
     * @param object $forminstance The form instance.
     */
    public function load_template_form(&$mform, $forminstance) {
        global $PAGE;

        $completionstr = get_string('activitycompletion', 'pulsecondition_activity');
        $mform->addElement('select', 'condition[activity][status]', $completionstr, $this->get_options());
        $mform->addHelpButton('condition[activity][status]', 'activitycompletion', 'pulsecondition_activity');

        $mform->addElement('static', 'condition[activity][modules]', get_string('selectactivity', 'pulsecondition_activity'),
            get_string('selectactivity_help', 'pulsecondition_activity'));
    }

    /**
     * Checks if the user has completed the specified activities.
     *
     * @param object $instancedata The instance data.
     * @param int $userid The user ID.
     * @param \completion_info|null $completion The completion information.
     * @return bool True if completed, false otherwise.
     */
    public function is_user_completed($instancedata, $userid, \completion_info $completion=null) {
        global $PAGE;

        // Get the notification suppres module ids.
        $additional = $instancedata->condition['activity'] ?? [];
        $modules = $additional['modules'] ?? [];

        if (!empty($modules)) {
            $result = [];

            if ($completion === null) {
                $completion = new \completion_info(get_course($instancedata->courseid));
            }

            $fastmodinfo = get_fast_modinfo($instancedata->courseid);
            // Find the completion status for all this suppress modules.
            foreach ($modules as $cmid) {

                $cminfo = get_coursemodule_from_id('', $cmid);
                $cminfo = $fastmodinfo->get_cm($cminfo->id);
                if (class_exists('\core_completion\cm_completion_details')) {
                    $cmcompletion = \core_completion\cm_completion_details::get_instance($cminfo, $userid);
                    if (method_exists('\core_completion\cm_completion_details', 'is_overall_complete')) {
                        // Moodle 4.2 and above.
                        $state = $cmcompletion->is_overall_complete();
                    } else {
                        $completiondata = $completion->get_data($cminfo, true, $userid);
                        $state = $this->is_overall_complete($cmcompletion, $completiondata);
                    }

                } else {
                    $completionclass = \core_completion\activity_custom_completion::get_cm_completion_class($cminfo->modname);
                    $state = (new $completionclass($cminfo, $userid))->get_overall_completion_state();
                }

                if ($state == COMPLETION_COMPLETE) {
                    $result[] = true;
                }

            }
            // If suppress operator set as all, check all the configures modules are completed.
            // Remove the schedule only if all the activites are completed.
            if (count($result) == count($modules)) {
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * Returns whether the overall completion state of this course module should be marked as complete or not.
     * This is based on the completion settings of the course module, so when the course module requires a passing grade,
     * it will only be marked as complete when the user has passed the course module. Otherwise, it will be marked as complete
     * even when the user has failed the course module.
     *
     * @param \completion_completion $instance The completion instance.
     * @param \stdClass $completiondata The completion data.
     * @return bool True when the module can be marked as completed.
     */
    public function is_overall_complete($instance, $completiondata): bool {
        $completionstates = [];
        if ($instance->is_manual()) {
            $completionstates = [COMPLETION_COMPLETE];
        } else if ($instance->is_automatic()) {
            // Successfull completion states depend on the completion settings.
            if (isset($completiondata->passgrade)) {
                // Passing grade is required. Don't mark it as complete when state is COMPLETION_COMPLETE_FAIL.
                $completionstates = [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS];
            } else {
                // Any grade is required. Mark it as complete even when state is COMPLETION_COMPLETE_FAIL.
                $completionstates = [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL];
            }
        }

        return in_array($instance->get_overall_completion(), $completionstates);
    }
    /**
     * Module completed.
     *
     * @param stdclass $eventdata
     * @return bool
     */
    public static function module_completed($eventdata) {
        global $CFG;

        require_once($CFG->dirroot . '/lib/completionlib.php');
        // Event data.
        $data = $eventdata->get_data();
        // Get the info for the context.
        list($context, $course, $cm) = get_context_info_array($data['contextid']);

        if ($eventdata instanceof \core\event\user_graded && $eventdata->get_grade()->grade_item->itemtype == 'mod') {
            // Get the module id.
            $gradeitem = $eventdata->get_grade()->grade_item;
            $cm = get_coursemodule_from_instance($gradeitem->itemmodule, $gradeitem->iteminstance);
            if (empty($cm)) {
                return false;
            }
            $cmid = $cm->id;

        } else {
            $cmid = $data['contextinstanceid'];
        }

        $userid = $data['relateduserid'];

        // Self condition instance.
        $condition = new self();
        // Course completion info.
        $completion = new \completion_info($course);

        // Get all the notification instance configures the suppress with this activity.
        $notifications = self::get_acitivty_notifications($cmid);

        foreach ($notifications as $notification) {
            // Get the notification suppres module ids.
            $additional = isset($notification->additional) ? json_decode($notification->additional, true) : '';
            $modules = $additional['modules'] ?? [];
            if (!empty($modules)) {
                // Remove the schedule only if all the activites are completed.
                $condition->trigger_instance($notification->instanceid, $userid);
            }
        }

        return true;
    }

    /**
     * Fetch the list of menus which is used the triggered ID in the access rules for the given method.
     *
     * Find the menus which contains the given ID in the access rule (Role or cohorts).
     *
     * @param int $id ID of the triggered method, Role or cohort id.
     * @return array
     */
    public static function get_acitivty_notifications($id) {
        global $DB;

        $like = $DB->sql_like('co.additional', ':value'); // Like query to fetch the instances assigned this module.

        $sql = "SELECT *, ai.id as id, ai.id as instanceid FROM {pulse_autoinstances} ai
            JOIN {pulse_autotemplates} pat ON pat.id = ai.templateid
            LEFT JOIN {pulse_condition} c ON c.templateid = ai.templateid AND c.triggercondition = 'activity'
            LEFT JOIN {pulse_condition_overrides} co ON co.instanceid = ai.id AND co.triggercondition = 'activity'
            WHERE $like AND (co.status > 0 OR (co.status IS NULL AND c.status > 0))";

        $params = ['activity' => '%"activity"%', 'value' => '%"'.$id.'"%'];

        $records = $DB->get_records_sql($sql, $params);

        return $records;
    }

}
