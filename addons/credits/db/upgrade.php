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
 * Upgrade steps for Pulse credits
 *
 * Documentation: {@link https://moodledev.io/docs/guides/upgrade}
 *
 * @package    pulseaddon_credits
 * @category   upgrade
 * @copyright  2024 bdecent GmbH <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the plugin upgrade steps from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_pulseaddon_credits_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024122604) {
        // Migrate credits data from local_pulsepro to pulse_options.
        if ($dbman->table_exists('local_pulsepro')) {
            $sql = "SELECT * FROM {local_pulsepro} WHERE credits IS NOT NULL";
            $records = $DB->get_records_sql($sql);

            foreach ($records as $record) {
                // Insert credit value.
                if (!empty($record->credits)) {
                    $data = new stdClass();
                    $data->pulseid = $record->pulseid;
                    $data->name = 'credits';
                    if (!$DB->record_exists('pulse_options', (array) $data)) {
                        $data->value = $record->credits;
                        $DB->insert_record('pulse_options', $data);
                    }
                }

                // Insert credit status.
                if (!empty($record->credits_status)) {
                    $data = new stdClass();
                    $data->pulseid = $record->pulseid;
                    $data->name = 'credits_status';
                    if (!$DB->record_exists('pulse_options', (array) $data)) {
                        $data->value = $record->credits_status;
                        $DB->insert_record('pulse_options', $data);
                    }
                }
            }

            // Set the pulsepro limit to pulse credits.
            if ($expire = get_config('local_pulsepro', 'creditsfield')) {
                set_config('creditsfield', $expire, 'pulseaddon_credits');
            }
        }

        // Rename the table local_pulsepro_credits to pulseaddon_credits.
        $table = new xmldb_table('local_pulsepro_credits');
        $creditstable = new xmldb_table('pulseaddon_credits');

        if ($dbman->table_exists($table)) {

            if (!$dbman->table_exists($creditstable)) {
                // Rename the existing table.
                $dbman->rename_table($table, 'pulseaddon_credits');
            } else {
                // Copy data if new table exists but empty.
                $sql = "INSERT INTO {pulseaddon_credits}
                        (pulseid, userid, credit, timecreated)
                        SELECT
                            lpc.pulseid,
                            lpc.userid,
                            lpc.credit,
                            lpc.timecreated
                        FROM {local_pulsepro_credits} lpc
                        WHERE NOT EXISTS (
                            SELECT 1
                            FROM {pulseaddon_credits} pc
                            WHERE pc.pulseid = lpc.pulseid
                            AND pc.userid = lpc.userid
                        )";
                $DB->execute($sql);
            }
        }

        // Migrate credits fields from configurable params to options[credits].
        $table = new xmldb_table('pulse_presets');
        if ($dbman->table_exists($table)) {
            $records = $DB->get_records('pulse_presets');
            foreach ($records as $record) {
                $params = json_decode($record->configparams, true);
                foreach ($params as $key => $param) {
                    if ($param == 'reactiondisplay') {
                        $params[$key] = 'options[reactiondisplay]';
                    }
                    if ($param == 'reactiontype') {
                        $params[$key] = 'options[reactiontype]';
                    }
                    if ($param == 'credits') {
                        $params[$key] = 'options[credits]';
                    }
                    if ($param == 'credits_status') {
                        $params[$key] = 'options[credits_status]';
                    }
                }
                $record->configparams = json_encode($params);
                $DB->update_record('pulse_presets', $record);
            }
        }
        if ($oldversion > 0) {
            upgrade_plugin_savepoint(true, 2024122604, 'pulseaddon', 'credits');
        }
    }

    return true;
}
