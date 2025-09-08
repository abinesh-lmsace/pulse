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
 * Pulse instance test instance generate defined.
 *
 * @package   pulseaddon_reminder
 * @copyright 2024 bdecent GmbH <https://bdecent.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Pulse module instance generator.
 */
class pulseaddon_reminder_generator extends testing_module_generator {

    /**
     * Module intro content.
     *
     * @var string
     */
    public $intro = 'Pulse test notification';

    /**
     * Default data.
     *
     * @param int $courseid
     * @return void
     */
    public function default_data(int $courseid) {
        return self::default_value() + ['course' => $courseid];
    }

    /**
     * Default pulse pro module data.
     *
     * @return array
     */
    public static function default_value() {

        $options = [
            "invitation_recipients" => '',
            "first_reminder" => 0,
            "first_subject" => "First pulse pro reminder",
            "first_content_editor" => ['text' => 'First reminder content', 'format' => FORMAT_HTML,
                'itemid' => file_get_unused_draft_itemid(),
            ],
            "first_contentformat" => '1',
            "first_recipients" => '',
            "first_schedule" => 0,
            "first_fixeddate" => strtotime(date('Y-m-d')),
            "first_relativedate" => 0,
            "second_reminder" => 0,
            "second_subject" => "Second pulse pro reminder",
            "second_content_editor" => ['text' => 'Second reminder content', 'format' => FORMAT_HTML,
                'itemid' => file_get_unused_draft_itemid(),
            ],
            "second_contentformat" => '1',
            "second_recipients" => '',
            "second_schedule" => 0,
            "second_fixeddate" => strtotime(date('Y-m-d')),
            "second_relativedate" => 0,
            "recurring_reminder" => 0,
            "recurring_subject" => "Recurring pulse pro reminder",
            "recurring_content_editor" => [
                'text' => 'Recurring reminder content', 'format' => FORMAT_HTML,
                'itemid' => file_get_unused_draft_itemid(),
            ],
            "recurring_contentformat" => '1',
            "recurring_recipients" => '5',
            "recurring_relativedate" => 45,
        ];

        return $options;
    }

}
