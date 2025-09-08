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
 * Class upgradelib handles the upgrade logic for the reminder addon in the pulse module.
 *
 * @package    pulseaddon_reminder
 * @copyright  2024 bdecent GmbH <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Class upgradelib handles the upgrade logic for the reminder addon in the pulse module.
 */
class upgradelib {

    /**
     * Initializes the upgrade process.
     *
     * @param int $oldversion The old version of the plugin.
     * @return void
     */
    public static function init($oldversion) {
        (new self())->upgrade($oldversion);
    }

    /**
     * Upgrades the database schema and data.
     *
     * @param int $oldversion The old version of the plugin.
     * @return void
     */
    public function upgrade($oldversion) {
        global $DB;

        // Get all records from availability table that don't exist in notifications.
        $sql = "SELECT lpa.*
                FROM {local_pulsepro_availability} lpa
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM {pulseaddon_reminder_notified} prn
                    WHERE prn.pulseid = lpa.pulseid
                    AND prn.userid = lpa.userid
                )";

        $records = $DB->get_recordset_sql($sql);

        $inserts = [];
        foreach ($records as $record) {

            if (!empty($record->first_reminder_status)) {
                $this->first_reminder($record, $inserts);
            }

            if (!empty($record->first_users)) {
                $this->first_reminder_foruser($record, $inserts);
            }

            if (!empty($record->second_reminder_status)) {
                $this->second_reminder($record, $inserts);
            }

            if (!empty($record->second_users)) {
                $this->second_reminder_foruser($record, $inserts);
            }

            if (!empty($record->recurring_reminder_time)) {
                $this->recurring_reminder($record, $inserts);
            }

            if (!empty($record->recurring_users)) {
                $this->recurring_reminder_foruser($record, $inserts);
            }

            if (!empty($record->invitation_users)) {
                $this->invitation_foruser($record, $inserts);
            }
        }

        $records->close();

        if (!empty($inserts)) {
            $chunks = array_chunk($inserts, 50);
            foreach ($chunks as $chunk) {
                $DB->insert_records('pulseaddon_reminder_notified', $chunk);
            }
        }
    }

    /**
     * Processes first reminder and prepares data for insertion.
     *
     * @param object $record The record containing pulse and user information.
     * @param array $inserts The array to store the prepared data for insertion.
     * @return void
     */
    public function first_reminder($record, &$inserts) {

        $data = new stdClass();
        $data->pulseid = $record->pulseid;
        $data->userid = $record->userid;
        $data->status = $record->status;
        $data->reminder_type = 'first';
        $data->reminder_status = 1;
        $data->reminder_time = $record->first_reminder_time;
        $data->foruserid = 0;
        $inserts[] = $data;
    }

    /**
     * Processes first reminder for a user and prepares data for insertion.
     *
     * @param object $record The record containing pulse and user information.
     * @param array $inserts The array to store the prepared data for insertion.
     * @return void
     */
    public function first_reminder_foruser($record, &$inserts) {
        $data = new stdClass();
        $data->pulseid = $record->pulseid;
        $data->userid = $record->userid;
        $data->status = $record->status;
        $data->reminder_type = 'first';
        $data->reminder_status = 1;
        $data->reminder_time = $record->first_reminder_time;

        if (!empty($data->first_users)) {
            $users = json_decode($data->first_users);
            foreach ($users as $user) {
                $data->foruserid = $user;
                $inserts[] = $data;
            }
        }
    }

    /**
     * Processes second reminder and prepares data for insertion.
     *
     * @param object $record The record containing pulse and user information.
     * @param array $inserts The array to store the prepared data for insertion.
     * @return void
     */
    public function second_reminder($record, &$inserts) {

        $data = new stdClass();
        $data->pulseid = $record->pulseid;
        $data->userid = $record->userid;
        $data->status = $record->status;
        $data->reminder_type = 'second';
        $data->reminder_status = 1;
        $data->reminder_time = $record->second_reminder_time;
        $data->foruserid = 0;

        $inserts[] = $data;
    }

    /**
     * Processes second reminder for a user and prepares data for insertion.
     *
     * @param object $record The record containing pulse and user information.
     * @param array $inserts The array to store the prepared data for insertion.
     * @return void
     */
    public function second_reminder_foruser($record, &$inserts) {
        $data = new stdClass();
        $data->pulseid = $record->pulseid;
        $data->userid = $record->userid;
        $data->status = $record->status;
        $data->reminder_type = 'second';
        $data->reminder_status = 1;
        $data->reminder_time = $record->second_reminder_time;

        if (!empty($data->second_users)) {
            $users = json_decode($data->second_users);
            foreach ($users as $user) {
                $data->foruserid = $user;
                $inserts[] = $data;
            }
        }
    }

    /**
     * Processes recurring reminder and prepares data for insertion.
     *
     * @param object $record The record containing pulse and user information.
     * @param array $inserts The array to store the prepared data for insertion.
     * @return void
     */
    public function recurring_reminder($record, &$inserts) {

        $data = new stdClass();
        $data->pulseid = $record->pulseid;
        $data->userid = $record->userid;
        $data->status = $record->status;
        $data->reminder_type = 'recurring';
        $data->reminder_status = 1;
        $data->reminder_time = $record->recurring_reminder_time;
        $data->foruserid = 0;

        $inserts[] = $data;

        if (!empty($record->recurring_reminder_prevtime)) {
            $prevtimes = json_decode($record->recurring_reminder_prevtime);
            if (!empty($prevtimes)) {
                foreach ($prevtimes as $prevtime) {
                    $data = new stdClass();
                    $data->pulseid = $record->pulseid;
                    $data->userid = $record->userid;
                    $data->status = $record->status;
                    $data->reminder_type = 'recurring';
                    $data->reminder_status = 1;
                    $data->reminder_time = $prevtime;
                    $data->foruserid = 0;
                    $inserts[] = $data;
                }
            }
        }
    }

    /**
     * Processes recurring reminder for a user and prepares data for insertion.
     *
     * @param object $record The record containing pulse and user information.
     * @param array $inserts The array to store the prepared data for insertion.
     * @return void
     */
    public function recurring_reminder_foruser($record, &$inserts) {

        $data = new stdClass();
        $data->pulseid = $record->pulseid;
        $data->userid = $record->userid;
        $data->status = $record->status;
        $data->reminder_type = 'recurring';
        $data->reminder_status = 1;
        $data->reminder_time = $record->recurring_reminder_time;

        if (!empty($data->recurring_users)) {
            $users = json_decode($data->recurring_users);
            foreach ($users as $user) {
                $data->foruserid = $user;
                $inserts[] = $data;
            }
        }
    }

    /**
     * Processes invitation for a user and prepares data for insertion.
     *
     * @param object $record The record containing pulse and user information.
     * @param array $inserts The array to store the prepared data for insertion.
     * @return void
     */
    public function invitation_foruser($record, &$inserts) {

        $data = new stdClass();
        $data->pulseid = $record->pulseid;
        $data->userid = $record->userid;
        $data->status = $record->status;
        $data->reminder_type = 'invitation';
        $data->reminder_status = 1;
        $data->reminder_time = $record->availabletime;

        if (!empty($data->invitation_users)) {
            $users = json_decode($data->invitation_users);
            foreach ($users as $user) {
                $data->foruserid = $user;
                $inserts[] = $data;
            }
        }

    }
}
