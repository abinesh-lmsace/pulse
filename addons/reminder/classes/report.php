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
 * Pulse reminder Report - contains the report class for the reminder addon in the pulse module.
 *
 * @package   pulseaddon_reminder
 * @copyright 2024 bdecent GmbH <https://bdecent.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_reminder;

use html_writer;

/**
 * Class report handles the report for the reminder addon in the pulse module.
 */
class report {

    /**
     * Get the formatted first reminder time.
     *
     * @param object $row The data row.
     * @param object $table The table object.
     * @return string The formatted first reminder time.
     */
    public static function col_first_reminder_time($row, $table) {
        return ($row->first_reminder_time != '') ?
            userdate($row->first_reminder_time, get_string('strftimedatetimeshort', 'core_langconfig')) : '';
    }

    /**
     * Get the formatted second reminder time.
     *
     * @param object $row The data row.
     * @param object $table The table object.
     * @return string The formatted second reminder time.
     */
    public static function col_second_reminder_time($row, $table) {
        return ($row->second_reminder_time != '') ?
            userdate($row->second_reminder_time, get_string('strftimedatetimeshort', 'core_langconfig')) : '';
    }

    /**
     * Get the formatted recurring reminder time and previous reminders.
     *
     * @param object $row The data row.
     * @param object $table The table object.
     * @return string The formatted recurring reminder time and previous reminders.
     */
    public static function col_recurring_reminder_time($row, $table) {
        if ($row->recurring_reminder_time != '') {
            $result = userdate($row->recurring_reminder_time, get_string('strftimedatetimeshort', 'core_langconfig'));
            $prevtime = (!empty($row->recurring_reminder_prevtime)) ? explode(',', $row->recurring_reminder_prevtime) : [];
            $prev = [];
            if (is_array($prevtime)) {
                foreach ($prevtime as $time) {
                    if (!empty($time)) {
                        $date = userdate($time, get_string('strftimedatetimeshort', 'core_langconfig'));
                        if (!$table->is_downloading()) {
                            $prev[] = html_writer::span(
                                userdate($time, get_string('strftimedatetimeshort', 'core_langconfig')),
                                'invitation-list d-block'
                            );
                        }
                    }
                }
                $prevhtml = html_writer::tag('label', get_string('previousreminders', 'pulse'),
                    ['class' => 'previous-reminders d-block']);

                $result .= (!empty($prev)) ? $prevhtml : '';
                $result .= (!empty($prev)) ? implode(' ', $prev) : '';
            }
            return $result;
        }
        return '';
    }
}
