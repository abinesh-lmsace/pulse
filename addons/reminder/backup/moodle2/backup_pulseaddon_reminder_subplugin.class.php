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
 * This file contains the backup code for the pulseaddon availability plugin.
 *
 * @package    pulseaddon_reminder
 * @copyright  2024 bdecent gmbh <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Provides the information to backup chapter elements.
 */
class backup_pulseaddon_reminder_subplugin extends backup_subplugin {

    /**
     * Returns the subplugin information to attach to chapter element
     *
     * @return backup_subplugin_element
     */
    protected function define_pulse_subplugin_structure() {

        $userinfo = $this->get_setting_value('userinfo');

        // Create XML elements.
        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());
        $subpluginelement = new backup_nested_element('pulseaddon_reminder', ['id'], [
            'pulseid', 'invitation_recipients', 'first_reminder', 'first_content', 'first_contentformat',
            'first_subject', 'first_recipients', 'first_schedule', 'first_fixeddate', 'first_relativedate',
            'second_reminder', 'second_content', 'second_contentformat', 'second_subject', 'second_recipients',
            'second_schedule', 'second_fixeddate', 'second_relativedate', 'recurring_reminder', 'recurring_content',
            'recurring_contentformat', 'recurring_subject', 'recurring_recipients', 'recurring_relativedate',
        ]);

        $remindernotification = new backup_nested_element('pulseaddon_reminder_notified_list');
        $remindernotificationelement = new backup_nested_element('pulseaddon_reminder_notified', ['id'], [
            'pulseid', 'userid', 'status', 'reminder_type', 'reminder_status', 'reminder_time', 'foruserid']);

        // Connect XML elements into the tree.
        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($subpluginelement);

        $subplugin->add_child($remindernotification);
        $remindernotification->add_child($remindernotificationelement);

        $subpluginelement->set_source_table('pulseaddon_reminder', ['pulseid' => backup::VAR_PARENTID]);

        // Set source to populate the data.
        if ($userinfo) {
            $remindernotificationelement->set_source_table('pulseaddon_reminder_notified', [
                'pulseid' => backup::VAR_PARENTID]);

            $remindernotificationelement->annotate_ids('user', 'userid');
        }

        // Define module file annotations.
        $subplugin->annotate_files('mod_pulse', 'first_content', null);
        $subplugin->annotate_files('mod_pulse', 'second_content', null);
        $subplugin->annotate_files('mod_pulse', 'recurring_content', null);

        return $subplugin;
    }

}
