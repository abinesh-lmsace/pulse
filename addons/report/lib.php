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
 * Callback implementations for pulseaddon_report
 *
 * @package    pulseaddon_report
 * @copyright  2024 bdecent GmbH <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the pulse reactions view reports page link to course administration section under reports category.
 *
 * @param  navigation_node $navigation Navigation nodes.
 * @param  stdclass $course Current course object.
 * @param  context $context Course context object.
 * @return void
 */
function pulseaddon_report_extend_navigation_course($navigation, $course, $context) {

    $node = $navigation->get('coursereports');

    if (has_capability('pulseaddons/report:viewreports', $context) && $node && get_config('pulseaddon_report', 'enabled')) {
        $url = new moodle_url('/mod/pulse/addons/report/index.php', ['id' => $course->id]);
        $node->add(get_string('reports', 'pulse'), $url, navigation_node::TYPE_SETTING, null, null,
            new pix_icon('i/report', ''));
    }

}
