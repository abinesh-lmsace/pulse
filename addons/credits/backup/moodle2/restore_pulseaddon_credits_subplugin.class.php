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
 * This file contains the restore code for the element_chapter plugin.
 *
 * @package   pulseaddon_credits
 * @copyright  2024 bdecent gmbh <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore subplugin class.
 *
 * Provides the necessary information needed to restore chapter element subplugin.
 */
class restore_pulseaddon_credits_subplugin extends restore_subplugin {

    /**
     * Returns the paths to be handled by the subplugin.
     * @return array
     */
    protected function define_pulse_subplugin_structure() {

        $paths = [];

        $userinfo = $this->get_setting_value('userinfo');

        if ($userinfo) {
            $elename = $this->get_namefor('instance');
            // We used get_recommended_name() so this works.
            $elepath = $this->get_pathfor('/pulseaddon_credits');
            $paths[] = new restore_path_element($elename, $elepath);
        }

        return $paths;
    }

    /**
     * Processes one chapter element instance
     * @param mixed $data
     */
    public function process_pulseaddon_credits_instance($data) {
        global $DB;

        $data = (object)$data;

        $oldavailablityid = $data->id;
        $data->pulseid = $this->get_new_parentid('pulse');
        $data->userid = $this->get_mappingid('user', $data->userid);
        // Make the chapter empty, content will be added during the contentdesigner_content restore.
        $DB->insert_record('pulseaddon_credits', $data);
    }
}
