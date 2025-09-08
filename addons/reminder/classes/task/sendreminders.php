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
 * Adhoc task definition to Send reminders to users.
 *
 * @package   pulseaddon_reminder
 * @copyright 2024, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace pulseaddon_reminder\task;

use context_module;
use moodle_exception;

/**
 * The Adhoc task sends the reminders notification. Task setup for each reminder separately.
 */
class sendreminders extends \core\task\adhoc_task {

    /**
     * Type of the reminder (first, second, recurring, invitation)
     *
     * @var string
     */
    public $type;

    /**
     * List of notified users, Used to update the notified users status after reminders are send to all users.
     *
     * @var array
     */
    public $notifiedusers = [];

    /**
     * Current pulse instance record data.
     *
     * @var stdclass
     */
    public $instance;

    /**
     * Adhoc task execution send the reminder to multiple roles.
     * Reminder are send to student roles, course context roles, and user context roles.
     *
     * Course context roles and user context roles are notified for each students separately.
     * @return true
     */
    public function execute() {

        global $DB, $CFG, $USER, $PAGE;

        require_once($CFG->dirroot.'/mod/pulse/lib.php');

        $customdata = $this->get_custom_data();

        $pulseid = $customdata->pulseid;
        $type = $customdata->type;
        $role = $customdata->role;

        $notification = new \pulseaddon_reminder\notification();
        $instances = $notification->get_instances('pl.id = :pulseid', ['pulseid' => $pulseid]);

        $instance = $instances[$pulseid];
        $instance->role = $role;
        $instance->type = $type;

        $this->instance = $instance;

        if (empty($instance)) {
            return true;
        }

        $this->type = $type;

        if (!$DB->record_exists('pulse', ['id' => $this->instance->pulse->id])) {
            return true;
        }

        // Store current user for update the user after filter.
        $currentuser = $USER;

        // Store the current page course and cm for support the filtercodes.
        $currentcourse = $PAGE->course;
        $currentcm = $PAGE->cm;
        $currentcontext = $PAGE->context;

        // Set the current pulse course as page course. Support for filter shortcodes.
        // Filtercodes plugin used $PAGE->course proprety for coursestartdate, course enddata and other course related shortcodes.
        // Tried to use $PAGE->set_course(), But the theme already completed the setup, so we can't use that moodle method.
        // For this reason, here updated the protected _course property using reflection.
        // Only if filtercodes fitler plugin installed and enabled.
        if (\mod_pulse\helper::change_pagevalue()) {

            $coursereflection = new \ReflectionProperty(get_class($PAGE), '_course');
            $coursereflection->setAccessible(true);
            $coursereflection->setValue($PAGE, $instance->course);

            // Setup the course module data to support filtercodes.
            $pulsecm = get_coursemodule_from_instance('pulse', $instance->pulse->id);
            $cmreflection = new \ReflectionProperty(get_class($PAGE), '_cm');
            $cmreflection->setAccessible(true);
            $cmreflection->setValue($PAGE, $pulsecm);

            $context = \context_module::instance($pulsecm->id);
            $contextreflection = new \ReflectionProperty(get_class($PAGE), '_context');
            $contextreflection->setAccessible(true);
            $contextreflection->setValue($PAGE, $context);
        }

        // Pulse moodle trace.
        pulse_mtrace('Sending reminders - '. $this->type);
        if ($instance->role == 'student') {

            $instance->users = $notification->get_student_users($instance, $this->type);

            if (!empty($instance->users)) {
                foreach ($instance->users as $user) {
                    if (isset($user->id)) {
                        if ($this->type == 'invitation') {
                            $condition = ['userid' => $user->id, 'pulseid' => $instance->pulse->id, 'status' => 1];
                            if (!$DB->record_exists('pulse_users', $condition)) {
                                pulse_mtrace(
                                    'Prepare '.$this->type.' reminder eventdata for the user - '. $user->id. ' for the pulse '.
                                    $instance->pulse->name
                                );
                                $this->send_notification($user, $instance);
                            }
                        } else {
                            if ($this->type == 'recurring' || (!isset($user->{$this->type.'_reminder_status'})
                                || $user->{$this->type.'_reminder_status'} == 0) ) {
                                pulse_mtrace('Prepare ' . $this->type . ' reminder eventdata for the user - ' . $user->id .
                                    ' for the pulse '. $instance->pulse->id
                                );
                                $this->send_notification($user, $instance);
                            }
                        }
                    }
                }
            }
        }
        // Send the notification to parents.
        if ($instance->role == 'usercontext') {

            $instance->users = $notification->get_parent_users($instance, $this->type);

            pulse_mtrace(" $this->type notification to user roles started");
            foreach ($instance->users as $parent) {
                pulse_mtrace("Prepare students data for the parent ". $parent->username);
                if (!empty($parent->students)) {
                    foreach ($parent->students as $studentid => $student) {
                        // Check the parent user notified about this student.
                        if (!$this->is_parent_notified($parent, $student)) {
                            $student->approveuser = $parent->id;
                            $this->send_notification($student, $instance, $parent, 'parent');
                        }
                    }
                } else {
                    pulse_mtrace("Parent doesn't have available students");
                }
            }
        }

        // Send the notification to teachers.
        if ($instance->role == 'coursecontext') {

            $instance->users = $notification->get_teacher_users($instance, $this->type);

            if (!empty($instance->users)) {
                pulse_mtrace(" $this->type notification to course roles started");

                foreach ($instance->users as $teacher) {
                    pulse_mtrace("Prepare students data for the teacher ". $teacher->username);
                    if (!empty($teacher->students)) {
                        foreach ($teacher->students as $studentid => $student) {
                            $student->approveuser = $teacher->id;
                            $this->send_notification($student, $instance, $teacher, 'teacher');
                        }
                    } else {
                        pulse_mtrace("Parent doesn't have available students");
                    }
                }
            }
        }

        pulse_mtrace('Completed the Send reminder for instance - '. $instance->pulse->id);

        // Only for filter codes.
        if (\mod_pulse\helper::change_pagevalue()) {
            // Return to current USER.
            \core\session\manager::set_user($currentuser);

            // SEtup the page course and cm to current values.
            $coursereflection->setValue($PAGE, $currentcourse);

            // Setup the course module data to support filtercodes.
            $cmreflection->setValue($PAGE, $currentcm);

            // Setup the module context to support filtercodes.
            $contextreflection->setValue($PAGE, $currentcontext);
        }

        return true;
    }

    /**
     * Check usercontext or other role users in coursecontext has notified for the student.
     *
     * @param  object $parent
     * @param  object $student
     * @return bool result of user notification
     */
    public function is_parent_notified($parent, $student) {
        if (!empty($parent->{$this->type.'_users'})) {
            $notified = json_decode($parent->{$this->type.'_users'});
            if ($this->type == 'recurring') {
                $comparetime = ($parent->recurring_reminder_time != '') ? $parent->recurring_reminder_time : time();
                $difference = time() - $comparetime;
                $duration = $this->instance->reminder->recurring_relativedate;
                if ($duration && $difference > $duration) {
                    return false;
                }
            }
            return (in_array($student->id, $notified)) ? true : false;
        }
        return false;
    }

    /**
     * Send reminder notification to available users. Users are filter by selected fixed date or relative date.
     * Once the reminders and invitations are send then it will updates the notified users list in availability table.
     *
     * @param  \stdclass $user User record data
     * @param  stdclass $instance Pulse instance record.
     * @param  object $sendto Notification sendto user or course context roles. otherwise it send to the $user.
     * @param  string $method Notification method.
     * @return void
     */
    public function send_notification($user, $instance, $sendto=null, $method='student') {
        global $DB, $CFG, $USER, $PAGE;

        require_once($CFG->dirroot.'/mod/pulse/lib.php');

        $course = (object) $instance->course;
        $context = (object) $instance->context;
        $pulse = (object) $instance->pulse;
        $pulseaddon = $instance->reminder;
        $type = $instance->type;
        $filearea = $type.'_content';

        // Use intro content as message text, if different pulse disabled.
        $subject = ($type == 'invitation') ? $instance->pulse->pulse_subject : $instance->reminder->{$type.'_subject'};
        if ($type == 'invitation') {
            $template = ($instance->pulse->diff_pulse) ? $instance->pulse->pulse_content : $pulse->intro;
            $filearea = ($instance->pulse->diff_pulse) ? 'pulse_content' : 'intro';
            $subject = ($instance->pulse->diff_pulse) ? $instance->pulse->pulse_subject : $pulse->name;
        } else {
            $template = $instance->reminder->{$type.'_content'};
        }

        // Find the sender for that user.
        $sender = \mod_pulse\task\sendinvitation::find_user_sender($instance->sender, $user->id);

        // Update the notification header and footer templates.
        self::join_notification_template($template);

        // Replace the email text placeholders with data.
        list($subject, $messagehtml) = \mod_pulse\helper::update_emailvars($template, $subject, $course, $user, $pulse, $sender);

        // Rewrite the plugin file placeholders in the email text.
        $messagehtml = file_rewrite_pluginfile_urls($messagehtml, 'pluginfile.php',
        $context->id, 'mod_pulse', $filearea, 0);

        // Set current student as user, filtercodes plugin uses current User data.
        \core\session\manager::set_user($user);
        $oldforcelang = force_current_language($user->lang); // Force the session lang to user lang.

        // Format filter supports. filter the enabled filters.
        $subject = format_text($subject, FORMAT_HTML);
        $messagehtml = format_text($messagehtml, FORMAT_HTML);
        $messageplain = html_to_text($messagehtml); // Plain text.
        // After format the message and subject return back to previous lang.
        force_current_language($oldforcelang);

        // Send message to user.
        pulse_mtrace( " Sending pulse to the user ". fullname($user) ."\n" );

        // User data who will receive notification.
        $sendto = ($sendto != null) ? $sendto : $user;
        // We got invitation sends multiple times to users.
        // Store the notified status of the users in "notify_users" table only for students,
        // for the other types of users we store the status in "pulseaddon_reminder" table.
        if ($type == 'invitation' && $method == 'student') {
            $this->notifiedusers[] = $sendto->id;
            // Insert before send to users.
            $transaction = $DB->start_delegated_transaction();
            try {
                if (\mod_pulse\helper::update_notified_user($sendto->id, $pulse)) {
                    $messagesend = \mod_pulse\helper::messagetouser(
                        $sendto, $subject, $messageplain, $messagehtml, $pulse, $sender
                    );
                    if (!$messagesend) {
                        throw new moodle_exception('invitationnotsend', 'pulse');
                    }
                } else {
                    throw new moodle_exception('invitationdbpro', 'pulse');
                }
            } catch (\Exception $e) {
                $transaction->rollback($e);
            }

            $transaction->allow_commit();

        } else {
            $messagesend = \mod_pulse\helper::messagetouser($sendto, $subject, $messageplain, $messagehtml, $pulse, $sender);
            if ($messagesend) {

                // Update reminders notification send status to prevent send notification on next adhoc task.
                $record = new \stdclass();

                $recurringverify = '';
                if ($type == 'recurring') {
                    $recurringverify = " AND reminder_type != 'recurring'";
                }

                $sql = 'SELECT *
                        FROM {pulseaddon_reminder_notified}
                        WHERE userid = :userid AND pulseid = :pulseid AND  reminder_type = :remindertype ' . $recurringverify;

                $params = ['userid' => $sendto->id, 'pulseid' => $pulse->id, 'remindertype' => $type];

                // Update the reminder status only for the student roles.
                $record->reminder_status = 1;
                $record->reminder_time = time();
                $record->reminder_type = $type;

                if ($method != 'student' && $sendto->id != $user->id) {
                    $record->foruserid = $user->id;
                    $sql .= ' AND foruserid = :foruserid';
                    $params['foruserid'] = $user->id;
                }

                if ($availdata = $DB->get_record_sql($sql, $params)) {
                    if (!empty((array) $record)) {
                        $record->id = $availdata->id;
                        $DB->update_record('pulseaddon_reminder_notified', $record);
                    }
                } else {
                    $record->pulseid = $instance->pulse->id;
                    $record->userid = $sendto->id;
                    $DB->insert_record('pulseaddon_reminder_notified', (array) $record);
                }
            }
        }

        return true;
    }

    /**
     * Join the global notification template with the invitation notification content.
     *
     * @param string $template
     * @return void
     */
    public static function join_notification_template(string &$template): void {
        global $CFG;

        $context = \context_system::instance();

        $header = get_config('mod_pulse', 'notificationheader');
        $headerhtml = file_rewrite_pluginfile_urls($header, 'pulginfile.php', $context->id, 'mod_pulse', 'notificationheader', 0);
        $headerhtml = format_text($headerhtml, FORMAT_HTML, ['trusted' => true, 'noclean' => true]);

        $footer = get_config('mod_pulse', 'notificationfooter');
        $footerhtml = file_rewrite_pluginfile_urls($footer, 'pulginfile.php', $context->id, 'mod_pulse', 'notificationfooter', 0);
        $footerhtml = format_text($footerhtml, FORMAT_HTML, ['trusted' => true, 'noclean' => true]);

        $template = $headerhtml . $template . $footerhtml;
    }

    /**
     * Set adhoc task for reminders send message for each instance.
     *
     * @param  stdclass $instance Pulse instnace data.
     * @param  string $type Notification reminder type (first, second, recurring, invitation).
     * @param  string $role Type notify users (parent, teacher, studnet).
     * @return void
     */
    public static function set_reminder_adhoctask($instance, $type, $role='student') {

        $task = new \pulseaddon_reminder\task\sendreminders();

        if (!empty($instance)) {
            $data = (object) ['pulseid' => $instance->pulse->id, 'type' => $type, 'role' => $role];
            $task->set_custom_data($data);
            $task->set_component('pulseaddon_reminder');
            \core\task\manager::queue_adhoc_task($task, true);
        }
    }
}
