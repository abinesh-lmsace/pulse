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
 * Reminder instance class.
 *
 * @package   pulseaddon_reminder
 * @copyright 2024 bdecent GmbH <https://bdecent.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_reminder;

use stdClass;

/**
 * Instance class for the reminder addon.
 */
class instance extends \mod_pulse\addon\base {

    /**
     * Fixed schedule type.
     */
    public const SCHEDULE_FIXED = 0;

    /**
     * Relative schedule type.
     */
    public const SCHEDULE_RELATIVE = 1;

    /**
     * Name of the the addon.
     *
     * @return string
     */
    public function get_name() {
        return 'reminder';
    }

    /**
     * Instance update call from pulse module. create or update the reminder data.
     *
     * @param stdclass $pulse pulse data.
     * @return void
     */
    public function instance_update($pulse) {
        $this->instance_add($pulse);
    }

    /**
     * Adds or updates a reminder instance for a given pulse.
     *
     * This method handles the creation or updating of reminder instances associated with a pulse.
     * It processes the reminder notifications (first, second, recurring) and saves the relevant
     * content and settings to the database.
     *
     * @param object $pulse The pulse object containing reminder details.
     * @return void
     */
    public function instance_add($pulse) {
        global $DB;

        if (!empty($pulse) && !empty($this->pulseid)) {

            $record = new stdclass();
            $record->pulseid = $this->pulseid;

            $context = \context_module::instance($pulse->coursemodule);

            // Reminders.
            $notifications = ['first', 'second', 'recurring'];
            foreach ($notifications as $reminder) {
                $record->{$reminder.'_reminder'} = $pulse->{$reminder.'_reminder'} ?? 0;

                $record->{$reminder.'_contentformat'} = $pulse->{$reminder.'_contentformat'};
                $record->{$reminder.'_subject'} = $pulse->{$reminder.'_subject'} ?? '';
                $record->{$reminder.'_recipients'} = $pulse->{$reminder.'_recipients'};
                if ($reminder != 'recurring') {
                    $record->{$reminder.'_schedule'} = $pulse->{$reminder.'_schedule'};
                    $record->{$reminder.'_fixeddate'} = $pulse->{$reminder.'_fixeddate'};
                }
                $record->{$reminder.'_relativedate'} = $pulse->{$reminder.'_relativedate'};
                $record->{$reminder.'_content'} = file_save_draft_area_files(
                    $pulse->{$reminder.'_content_editor'}['itemid'], $context->id, 'mod_pulse',
                    $reminder.'_content', 0, ['subdirs' => true], $pulse->{$reminder.'_content_editor'}['text']);
            }
            $record->invitation_recipients = $pulse->invitation_recipients ?? '';

            if (isset($pulse->resend_pulse) && $pulse->resend_pulse) {
                $this->reset_invitation($pulse->id);
            }

            if ($existing = $DB->get_record('pulseaddon_reminder', ['pulseid' => $this->pulseid])) {
                $record->id = $existing->id;
                $DB->update_record('pulseaddon_reminder', $record);
            } else {
                $id = $DB->insert_record('pulseaddon_reminder', $record);
            }

            $completiontimeexpected = !empty($pulse->completionexpected) ? $pulse->completionexpected : null;
            \core_completion\api::update_completion_date_event(
                $pulse->coursemodule, 'pulse', $this->pulseid, $completiontimeexpected);
        }
    }

    /**
     * Deletes the instance of the reminder addon.
     *
     * This method checks if a record exists in the 'pulseaddon_reminder' table with the current instance's pulse ID.
     * If such a record exists, it deletes the record and removes the associated user data.
     *
     * @return void
     */
    public function instance_delete() {
        global $DB;

        if ($DB->record_exists('pulseaddon_reminder', ['pulseid' => $this->pulseid])) {
            $DB->delete_records('pulseaddon_reminder', ['pulseid' => $this->pulseid]);
            $this->remove_userdata();
        }
    }

    /**
     * Reset invitation.
     *
     * @return void
     */
    public function reset_invitation() {
        global $DB;
        $DB->delete_records('pulseaddon_reminder_notified',
            ['pulseid' => $this->pulseid, 'reminder_type' => 'invitation']);
    }

    /**
     * Remove all user's data related to the pulse instance. Function triggered when the pulse instance is deleted.
     * Removes the user's availability data related to the instance and created tokens for reactions.
     *
     * @return void
     */
    public function remove_userdata() {
        global $DB;
        // Remove pulse availability records.
        if ($DB->record_exists('pulseaddon_availability', ['pulseid' => $this->pulseid])) {
            $DB->delete_records('pulseaddon_availability', ['pulseid' => $this->pulseid]);
        }

        // Remove pulse tokens completion records.
        if ($DB->record_exists('pulseaddon_reaction_tokens', ['pulseid' => $this->pulseid])) {
            $DB->delete_records('pulseaddon_reaction_tokens', ['pulseid' => $this->pulseid]);
        }

        $DB->delete_records('pulseaddon_reminder_notified', ['pulseid' => $this->pulseid]);
    }

    /**
     * Sends invitation notifications via a cron task.
     *
     * This method initializes the notification class and triggers the sending
     * of invitations. It is intended to be called by a scheduled cron job.
     *
     * @return bool Always returns true.
     */
    public static function invitation_cron_task() {
        $notification = new \pulseaddon_reminder\notification();
        $notification->send_invitations();
        return true;
    }

    /**
     * Adds form fields for reminder settings before appearance.
     *
     * @param MoodleQuickForm $mform The form being built.
     * @param object $instance The instance of the reminder.
     * @return void
     */
    public static function form_fields_before_appearance(&$mform, $instance) {

        // Get the roles available for the course.
        $roles = $instance->course_roles();

        // Roles of recipients that need ot receive Invitation.
        $select = $mform->addElement('autocomplete', 'invitation_recipients', get_string('recipients', 'pulse'), $roles);
        $select->setMultiple(true);
        $mform->addHelpButton('invitation_recipients', 'recipients', 'mod_pulse');

        // ...  First reminder section.
        // First Reminder.
        $mform->addElement('header', 'first_reminders', get_string('head:firstreminder', 'mod_pulse'));

        // Enable / disable first reminder.
        $mform->addElement('checkbox', 'first_reminder', get_string('enablereminder:first', 'pulse'),
            get_string('enable:disable', 'pulse'));
        $mform->addHelpButton('first_reminder', 'enablereminder:first', 'mod_pulse');

        // First reminder subject.
        $mform->addElement('text', 'first_subject', get_string('remindersubject', 'pulse'), ['size' => '64' ]);
        $mform->setType('first_subject', PARAM_RAW);
        $mform->addHelpButton('first_subject', 'remindersubject', 'mod_pulse');

        // First reminder content.
        $editoroptions  = \mod_pulse\helper::get_editor_options();
        $mform->addElement('editor', 'first_content_editor', get_string('remindercontent', 'pulse'),
        ['class' => 'fitem_id_templatevars_editor'], $editoroptions);
        $mform->setType('first_content_editor', PARAM_RAW);
        $placeholders = pulse_email_placeholders('firstcontent', false);
        $mform->addElement('html', $placeholders);
        $mform->addHelpButton('first_content_editor', 'remindercontent', 'mod_pulse');

        // Reminder recipients.
        $select = $mform->addElement('autocomplete', 'first_recipients',
                    get_string('recipients', 'pulse'), $roles);
        $select->setMultiple(true);
        $mform->addHelpButton('first_recipients', 'recipients', 'mod_pulse');

        // Schedule.
        $group = [];
        $radioarray = [];
        $radioarray[] = $mform->createElement('radio', 'first_schedule', '',
            get_string('schedule:fixeddate', 'pulse'), self::SCHEDULE_FIXED);

        $radioarray[] = $mform->createElement('radio', 'first_schedule', '',
            get_string('schedule:relativedate', 'pulse'), self::SCHEDULE_RELATIVE);
        $mform->addGroup($radioarray, 'first_schedule_arr', get_string('reminderschedule', 'pulse'), [' '], false);
        $mform->addHelpButton('first_schedule_arr', 'reminderschedule', 'mod_pulse');
        // Fixed date.
        $mform->addElement('date_time_selector', 'first_fixeddate', '');
        $mform->hideIf('first_fixeddate', 'first_schedule', 'neq', self::SCHEDULE_FIXED);
        // Relative date.
        $mform->addElement('duration', 'first_relativedate', '');
        $mform->hideIf('first_relativedate', 'first_schedule', 'neq', self::SCHEDULE_RELATIVE);

        // ...  Second reminder section.

        // Second reminder.
        $mform->addElement('header', 'second_reminders', get_string('head:secondreminder', 'mod_pulse') );

        // Enable / disable second reminder.
        $mform->addElement('checkbox', 'second_reminder', get_string('enablereminder:second', 'pulse'),
            get_string('enable:disable', 'pulse'));
        $mform->addHelpButton('second_reminder', 'enablereminder:second', 'mod_pulse');

        // Second reminder subject.
        $mform->addElement('text', 'second_subject', get_string('remindersubject', 'pulse'), ['size' => '64']);
        $mform->setType('second_subject', PARAM_RAW);
        $mform->addHelpButton('second_subject', 'remindersubject', 'mod_pulse');

        $mform->addElement('editor', 'second_content_editor', get_string('remindercontent', 'pulse'),
        ['class' => 'fitem_id_templatevars_editor'], $editoroptions);
        $mform->setType('second_content_editor', PARAM_RAW);
        $placeholders = pulse_email_placeholders('secondcontent', false);
        $mform->addElement('html', $placeholders);
        $mform->addHelpButton('second_content_editor', 'remindercontent', 'mod_pulse');

        $select = $mform->addElement('autocomplete', 'second_recipients',
                    get_string('recipients', 'pulse'), $roles);
        $select->setMultiple(true);
        $mform->addHelpButton('second_recipients', 'recipients', 'mod_pulse');

        $radioarray = [];
        $radioarray[] = $mform->createElement('radio', 'second_schedule', '',
            get_string('schedule:fixeddate', 'pulse'), self::SCHEDULE_FIXED);

        $radioarray[] = $mform->createElement('radio', 'second_schedule', '',
            get_string('schedule:relativedate', 'pulse'), self::SCHEDULE_RELATIVE);

        $mform->addGroup($radioarray, 'second_schedule_arr', get_string('reminderschedule', 'pulse'), [' '], false);
        $mform->addHelpButton('second_schedule_arr', 'reminderschedule', 'mod_pulse');

        $mform->addElement('date_time_selector', 'second_fixeddate', '');
        $mform->hideIf('second_fixeddate', 'second_schedule', 'neq', self::SCHEDULE_FIXED);

        $mform->addElement('duration', 'second_relativedate', '');
        $mform->hideIf('second_relativedate', 'second_schedule', 'neq', self::SCHEDULE_RELATIVE);

        // ...  Recurring reminder section.
        // Recurring reminder.
        $mform->addElement('header', 'recurring_reminders', get_string('head:recurringreminder', 'mod_pulse'));

        // Enable / disable recurring reminder.
        $mform->addElement('checkbox', 'recurring_reminder', get_string('enablereminder:recurring', 'pulse'),
            get_string('enable:disable', 'pulse'));
        $mform->addHelpButton('recurring_reminder', 'enablereminder:recurring', 'mod_pulse');

        // Recurring reminder subject.
        $mform->addElement('text', 'recurring_subject', get_string('remindersubject', 'pulse'), ['size' => '64']);
        $mform->setType('recurring_subject', PARAM_RAW);
        $mform->addHelpButton('recurring_subject', 'remindersubject', 'mod_pulse');
        // Recurring reminder content.
        $mform->addElement('editor', 'recurring_content_editor', get_string('remindercontent', 'pulse'),
        ['class' => 'fitem_id_templatevars_editor'], $editoroptions);
        $mform->setType('recurring_content_editor', PARAM_RAW);
        $placeholders = pulse_email_placeholders('recurringcontent', false);
        $mform->addElement('html', $placeholders);
        $mform->addHelpButton('recurring_content_editor', 'remindercontent', 'mod_pulse');

        // Recurring recipients.
        $select = $mform->addElement('autocomplete', 'recurring_recipients', get_string('recipients', 'pulse'), $roles);
        $select->setMultiple(true);
        $mform->addHelpButton('recurring_recipients', 'recipients', 'mod_pulse');
        // Recurring Relative Date.
        $mform->addElement('duration', 'recurring_relativedate', get_string('reminderschedule', 'pulse'));
        $mform->addHelpButton('recurring_relativedate', 'reminderschedule', 'mod_pulse');
    }

    /**
     * Extended the Pulse module add/update form validation method.
     *
     * @param MoodleQuickForm $mform The form object
     * @param MoodleQuickForm $modform The module form object
     * @param array $data The data submitted by the form
     * @param array $files The files submitted by the form
     * @return array $errors List of errors.
     */
    public static function form_validation($mform, $modform, $data, $files) {
        $reminders = ['first', 'second', 'recurring'];

        $errors = [];
        if ($data['pulse'] && empty($data['invitation_recipients'])) {
            $errors['invitation_recipients'] = get_string('required');
        }
        foreach ($reminders as $step) {
            $reminder = $step.'_reminder';
            if (isset($data[$reminder]) && $data[$reminder]) {
                if (empty($data[$step.'_subject'])) {
                    $errors[$step.'_subject'] = get_string('required');
                }
                if (empty($data[$step.'_content_editor']['text'])) {
                    $errors[$step.'_content_editor'] = get_string('required');
                }
                if (empty($data[$step.'_recipients'])) {
                    $errors[$step.'_recipients'] = get_string('required');
                }

                if ($step != 'recurring') {
                    if ($data[$step.'_schedule'] == 1 && !$data[$step.'_relativedate']) {
                        $errors[$step.'_relativedate'] = get_string('required');
                    }
                    if ($data[$step.'_schedule'] == 0 && !$data[$step.'_fixeddate']) {
                        $errors[$step.'_fixeddate'] = get_string('required');
                    }
                } else {
                    if (!$data[$step.'_relativedate']) {
                        $errors[$step.'_relativedate'] = get_string('required');
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Post-processes the given data by formatting and setting content and recipients for notifications.
     *
     * @param stdClass $data The data object containing notification information.
     */
    public static function data_postprocessing($data) {

        $notifications = ['first', 'second', 'recurring'];

        foreach ($notifications as $reminder) {
            $var = $reminder.'_content_editor';

            if (isset($data->$var)) {
                $editorcontent = $data->{$var};
                $data->{$reminder.'_contentformat'} = $editorcontent['format'];
                $data->{$reminder.'_content'} = $editorcontent['text'];
            }

            $data->{$reminder.'_recipients'} = isset($data->{$reminder.'_recipients'})
                && !empty($data->{$reminder.'_recipients'}) ? implode(',', $data->{$reminder.'_recipients'}) : '';
        }

        $data->invitation_recipients = isset($data->invitation_recipients) ? implode(',', $data->invitation_recipients) : '';

    }

    /**
     * Preprocesses data for the reminder instance.
     *
     * This method prepares the default values for the reminder instance form, including
     * setting up draft areas for editor fields and populating recipient lists.
     *
     * @param array $defaultvalues The default values for the form.
     * @param object $currentinstance The current instance of the reminder.
     * @param object $context The context in which the reminder is being created or edited.
     * @return void
     */
    public static function data_preprocessing(&$defaultvalues, $currentinstance, $context) {
        global $DB;

        $notifications = ['first', 'second', 'recurring'];
        $editoroptions = \mod_pulse\helper::get_editor_options();

        if (!isset($defaultvalues['id']) || $defaultvalues['id'] == null) {
            return '';
        }

        $prodata = $DB->get_record('pulseaddon_reminder', ['pulseid' => $defaultvalues['id']]);
        if (!empty($prodata) && !empty($prodata->id)) {

            foreach ($notifications as $reminder) {
                if ($currentinstance) {
                    // Prepare draft item id to store the files.
                    $draftitemid = file_get_submitted_draft_itemid($reminder.'_content');
                    $defaultvalues[$reminder.'_content_editor']['text'] = file_prepare_draft_area(
                        $draftitemid, $context->id, 'mod_pulse', $reminder.'_content', false, $editoroptions,
                        $prodata->{$reminder.'_content'});

                    $defaultvalues[$reminder.'_content_editor']['format'] = $prodata->{$reminder.'_contentformat'};
                    $defaultvalues[$reminder.'_content_editor']['itemid'] = $draftitemid;
                } else {
                    $draftitemid = file_get_submitted_draft_itemid($reminder.'_content_editor');
                    file_prepare_draft_area($draftitemid, null, 'mod_pulse', $reminder.'_content', false);
                    $defaultvalues[$reminder.'_content_editor']['format'] = editors_get_preferred_format();
                    $defaultvalues[$reminder.'_content_editor']['itemid'] = $draftitemid;
                }
                $defaultvalues[$reminder.'_recipients'] = $prodata->{$reminder.'_recipients'}
                    ? explode(',', $prodata->{$reminder.'_recipients'}) : [];
            }
            $defaultvalues['invitation_recipients'] = $prodata->invitation_recipients
                ? explode(',', $prodata->invitation_recipients) : [];

            $defaultvalues = array_merge($defaultvalues, (array) $prodata);
        }
    }

    /**
     * Adds columns related to reminders to the report.
     *
     * @param array $headers The headers of the report table.
     * @param array $columns The columns of the report table.
     * @param array $callbacks The callbacks for generating column data.
     */
    public static function report_add_columns(&$headers, &$columns, &$callbacks) {

        $columns[] = 'first_reminder_time';
        $headers[] = get_string('reminders:first', 'mod_pulse');
        $callbacks['first_reminder_time'] = 'pulseaddon_reminder\report::col_first_reminder_time';

        $columns[] = 'second_reminder_time';
        $headers[] = get_string('reminders:second', 'mod_pulse');
        $callbacks['second_reminder_time'] = 'pulseaddon_reminder\report::col_second_reminder_time';

        $columns[] = 'recurring_reminder_time';
        $headers[] = get_string('reminders:recurring', 'mod_pulse');
        $callbacks['recurring_reminder_time'] = 'pulseaddon_reminder\report::col_recurring_reminder_time';
    }

    /**
     * Decodes the contents for the pulse addon reminder during the restore process.
     *
     * @param array $contents The array of contents to be decoded.
     * @return array The updated array of contents with the decoded content added.
     */
    public static function restore_decode_contents(&$contents) {
        $contents[] = new \restore_decode_content('pulseaddon_reminder', [
            'first_content', 'second_content', 'recurring_content'], 'pulse');

        return $contents;
    }

    /**
     * Returns list of fileareas used in the pulseaddon reminder contents.
     *
     * @return array list of filearea to support pluginfile.
     */
    public static function pluginfile_filearea(): array {
        return ['first_content', 'second_content', 'recurring_content'];
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
            if ($DB->record_exists('pulseaddon_reminder_notified', ['pulseid' => $pulseid])) {
                $DB->delete_records('pulseaddon_reminder_notified', ['pulseid' => $pulseid]);
            }
            // Remove all the users invitations and users availability records related to that instnace.
            $DB->delete_records('pulseaddon_reminder', ['pulseid' => $pulseid]);
        }
    }

}
