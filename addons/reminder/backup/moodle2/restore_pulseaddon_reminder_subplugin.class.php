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
 * This file contains the restore code for the pulse reminder addon plugin.
 *
 * @package   pulseaddon_reminder
 * @copyright  2024 bdecent gmbh <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore subplugin class.
 *
 * Provides the necessary information needed to restore chapter element subplugin.
 */
class restore_pulseaddon_reminder_subplugin extends restore_subplugin {

    /**
     * Returns the paths to be handled by the subplugin.
     * @return array
     */
    protected function define_pulse_subplugin_structure() {

        $paths = [];

        $userinfo = $this->get_setting_value('userinfo');

        $elename = $this->get_namefor('instance');
        // We used get_recommended_name() so this works.
        $elepath = $this->get_pathfor('/pulseaddon_reminder');
        $paths[] = new restore_path_element($elename, $elepath);

        if ($userinfo) {

            $notificationslots = new restore_path_element('pulseaddon_reminder_notified',
                '/activity/pulse/pulseaddon_reminder_notified_list/pulseaddon_reminder_notified');
            $paths[] = $notificationslots;
        }

        return $paths;
    }

    /**
     * Processes one chapter element instance
     * @param mixed $data
     */
    public function process_pulseaddon_reminder_instance($data) {
        global $DB;

        $data = (object)$data;

        $oldavailablityid = $data->id;
        $data->pulseid = $this->get_new_parentid('pulse');
        $DB->insert_record('pulseaddon_reminder', $data);
    }

    /**
     * Restore the question element slots for user attempt.
     *
     * @param array $data
     * @return void
     */
    public function process_pulseaddon_reminder_notified($data) {
        global $DB;

        $data = (object) $data;

        $data->pulseid = $this->get_new_parentid('pulse');

        $olduserid = $data->userid;
        $data->userid = $this->get_mappingid('user', $olduserid, 0);
        $data->foruserid = $this->get_mappingid('user', $data->foruserid, 0);

        $DB->insert_record('pulseaddon_reminder_notified', $data);

    }

}
