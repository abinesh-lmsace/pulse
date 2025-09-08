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
 * Duration form element definition.
 *
 * @package    pulseaction_notification
 * @copyright  2024 bdecent GmbH <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/lib/form/duration.php');

defined('MONTHSECS') || define('MONTHSECS', 2628000);

/**
 * Duration form element.
 */
class moodlequickform_pulseactionduration extends MoodleQuickForm_duration {

    /**
     * Units used in this element.
     *
     * @var array
     */
    protected $_units = null;

    /**
     * Returns time associative array of unit length.
     *
     * @return array unit length in seconds => string unit name.
     */
    public function get_units() {
        if (is_null($this->_units)) {
            $this->_units = [
                MONTHSECS => get_string('months', 'mod_pulse'),
                WEEKSECS => get_string('weeks'),
                DAYSECS => get_string('days'),
                HOURSECS => get_string('hours'),
                MINSECS => get_string('minutes'),
                1 => get_string('seconds'),
            ];
        }
        return $this->_units;
    }
}
