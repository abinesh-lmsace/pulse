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
 * Strings for "Session module condition", language 'en'.
 *
 * @package   pulsecondition_session
 * @copyright 2023, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Session booking';
$string['sessionbooking'] = 'Session booking';
$string['sessionbooking_help'] = '<b>Session:</b> This automation will be triggered when a session is booked within the course. This trigger is only available within the course and should be selected within the automation instance.The options for session triggers include:<br><b>Disabled:</b> Session trigger is disabled.<br><b>All:</b> Session trigger applies to all enrolled users.<br><b>Upcoming:</b> Session trigger only applies to future enrolments.';
$string['sessionmodule'] = 'Session module';
$string['sessionmodule_help'] = 'You can configure the <b>Session Module</b> setting when creating an instance on the course automation page. The Session Module setting allows you to select from all available face-to-face activities within your course that have completion criteria configured. This selection determines which specific face-to-face activities will trigger the automation when their completion conditions are met.';
