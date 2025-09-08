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
 * Upgrade steps for Pulse Reactions
 *
 * Documentation: {@link https://moodledev.io/docs/guides/upgrade}
 *
 * @package    pulseaddon_reaction
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
function xmldb_pulseaddon_reaction_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024122607) {
        // Check if old table exists.
        // Rename the table local_pulsepro_credits to pulseaddon_credits.
        $table = new xmldb_table('local_pulsepro_tokens');
        $tokenstable = new xmldb_table('pulseaddon_reaction_tokens');

        if ($dbman->table_exists($table)) {

            if (!$dbman->table_exists($tokenstable)) {
                // Rename the existing table.
                $dbman->rename_table($table, 'pulseaddon_reaction_tokens');
            } else {
                // Migrate reaction settings to pulse_options.
                // Copy tokens data.
                $sql = "INSERT INTO {pulseaddon_reaction_tokens}
                        (pulseid, userid, relateduserid, token, reactiontype, status, timemodified, timecreated)
                        SELECT
                            lpt.pulseid,
                            lpt.userid,
                            lpt.relateduserid,
                            lpt.token,
                            lpt.reactiontype,
                            lpt.status,
                            lpt.timemodified,
                            lpt.timecreated
                        FROM {local_pulsepro_tokens} lpt
                        WHERE NOT EXISTS (
                            SELECT 1
                            FROM {pulseaddon_reaction_tokens} prt
                            WHERE prt.pulseid = lpt.pulseid
                            AND prt.userid = lpt.userid
                        )";

                $DB->execute($sql);
            }
        }

        if ($dbman->table_exists('local_pulsepro')) {
            // Migrate reaction settings to pulse_options.
            $sql = "SELECT id, pulseid, reactiontype, reactiondisplay
                    FROM {local_pulsepro}
                    WHERE reactiontype IS NOT NULL";

            $records = $DB->get_records_sql($sql);

            $inserts = [];
            foreach ($records as $record) {
                if (!empty($record->reactiontype)) {
                    $data = new stdClass();
                    $data->pulseid = $record->pulseid;
                    $data->name = 'reactiontype';
                    if (!$DB->record_exists('pulse_options', (array) $data)) {
                        $data->value = $record->reactiontype;
                        $inserts[] = $data;
                    }

                    if (isset($record->reactiondisplay)) {
                        $data = new stdClass();
                        $data->pulseid = $record->pulseid;
                        $data->name = 'reactiondisplay';
                        if (!$DB->record_exists('pulse_options', (array) $data)) {
                            $data->value = $record->reactiondisplay;
                            $inserts[] = $data;
                        }
                    }
                }
            }

            $DB->insert_records('pulse_options', $inserts);

            // Set the pulsepro limit to pulse reaction.
            if ($expire = get_config('local_pulsepro', 'expiretime')) {
                set_config('expiretime', $expire, 'pulseaddon_reaction');
            }
        }

        if ($oldversion > 0) {
            upgrade_plugin_savepoint(true, 2024122607, 'pulseaddon', 'reaction');
        }
    }

    return true;
}
