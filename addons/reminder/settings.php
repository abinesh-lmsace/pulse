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
 * Settings for pulse addon reminder.
 *
 * @package    pulseaddon_reminder
 * @copyright  2024 bdecent GmbH <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    global $ADMIN;

    // Notification header.
    $name = 'mod_pulse/notificationheader';
    $title = get_string('notificationheader', 'pulse');
    $description = get_string('notificationheaderdesc', 'pulse', [
        'placeholders' => pulse_email_placeholders('notificationheader', false)]);
    $setting = new admin_setting_confightmleditor($name, $title, $description, '');
    $settings->add($setting);

    // Notification footer.
    $name = 'mod_pulse/notificationfooter';
    $title = get_string('notificationfooter', 'pulse');
    $description = get_string('notificationfooterdesc', 'pulse', [
        'placeholders' => pulse_email_placeholders('notificationfooter', false)]);
    $setting = new admin_setting_confightmleditor($name, $title, $description, '');
    $settings->add($setting);

    if ($ADMIN->fulltree) {
        // Email tempalte placholders.
        $PAGE->requires->js_call_amd('mod_pulse/module', 'init', [$CFG->branch]);

        // Email placholders tab.
        $PAGE->requires->js_call_amd('mod_pulse/vars', 'init');
    }
}
