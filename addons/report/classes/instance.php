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

namespace pulseaddon_report;

use moodle_url;

/**
 * Class instance
 *
 * @package    pulseaddon_report
 * @copyright  2024 bdecent GmbH <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance extends \mod_pulse\addon\base {

    /**
     * Get the name of the addon.
     *
     * @return string
     */
    public function get_name() {
        return 'report';
    }

    /**
     * Get the pulse instance list from the given course.
     *
     * @param int $courseid Course ID
     * @return array Course instance list
     */
    public static function get_course_instancelist($courseid) {
        global $DB;

        $sql = "SELECT cm.*, pl.name
                FROM {course_modules} cm
                JOIN {pulse} pl ON pl.id = cm.instance
                WHERE cm.course=:courseid
                AND cm.module IN (
                    SELECT id FROM {modules} WHERE name=:pulse
                )";

        return $DB->get_records_sql($sql, ['courseid' => $courseid, 'pulse' => 'pulse']);
    }

    /**
     * Redirects the user to the pulse report page if they have the required capability.
     *
     * @param context $context The context of the pulse module.
     * @param stdClass $cm The course module object.
     * @param stdClass $course The course object.
     * @return void
     */
    public static function pulse_view_hook($context, $cm, $course) {
        global $USER;

        if (has_capability('pulseaddons/report:viewreports', $context, $USER->id)) {
            $redirecturl = new moodle_url('/mod/pulse/addons/report/report.php', ['courseid' => $course->id, 'cmid' => $cm->id]);
            redirect($redirecturl);
        }
    }

}
