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
 * Upgrade script for Pulse Reactions
 *
 * @package   pulseaddon_reminder
 * @copyright 2024 bdecent GmbH <https://bdecent.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Executed on upgradation of Pulse Reactions.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_pulseaddon_reminder_upgrade($oldversion) {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024122604) {
        // Define table pulseaddon_reminder to be created.
        require_once($CFG->dirroot . '/mod/pulse/addons/reminder/upgradelib.php');

        if ($dbman->table_exists('local_pulsepro_availability')) {
            upgradelib::init($oldversion);
        }

        if ($dbman->table_exists('local_pulsepro') && $dbman->table_exists('pulseaddon_reminder')) {
            // Get all records from local_pulsepro.
            // Copy data from local_pulsepro to pulseaddon_reminder using SQL.
            $sql = "INSERT INTO {pulseaddon_reminder} (
                        pulseid, invitation_recipients, first_reminder, first_content,
                        first_contentformat, first_subject, first_recipients, first_schedule, first_fixeddate, first_relativedate,
                        second_reminder, second_content, second_contentformat, second_subject, second_recipients, second_schedule,
                        second_fixeddate, second_relativedate, recurring_reminder, recurring_content, recurring_contentformat,
                        recurring_subject, recurring_recipients, recurring_relativedate
                    )
                    SELECT
                        lp.pulseid, lp.invitation_recipients, lp.first_reminder, lp.first_content,
                        lp.first_contentformat, lp.first_subject, lp.first_recipients, lp.first_schedule,
                        lp.first_fixeddate, lp.first_relativedate, lp.second_reminder, lp.second_content,
                        lp.second_contentformat, lp.second_subject, lp.second_recipients, lp.second_schedule,
                        lp.second_fixeddate, lp.second_relativedate, lp.recurring_reminder, lp.recurring_content,
                        lp.recurring_contentformat, lp.recurring_subject, lp.recurring_recipients, lp.recurring_relativedate
                    FROM {local_pulsepro} lp
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM {pulseaddon_reminder} pr
                        WHERE pr.pulseid = lp.pulseid
                    )";

            $DB->execute($sql);

            // Set the pulsepro limit to mod_pulse.
            if ($limit = get_config('local_pulsepro', 'tasklimituser')) {
                set_config('tasklimituser', $limit, 'mod_pulse');
            }
        }
        if ($oldversion > 0) {
            upgrade_plugin_savepoint(true, 2024122604, 'pulseaddon', 'reminder');
        }
    }

    if ($oldversion < 2024122605 && $oldversion > 0) {
        // Rename table pulseaddon_reminder_notifications to pulseaddon_reminder_notified.
        $table = new xmldb_table('pulseaddon_reminder_notifications');
        if ($dbman->table_exists($table) && !$dbman->table_exists('pulseaddon_reminder_notified')) {
            $dbman->rename_table($table, 'pulseaddon_reminder_notified');
        }
        // Save upgrade point.
        upgrade_plugin_savepoint(true, 2024122605, 'pulseaddon', 'reminder');
    }

    return true;
}
