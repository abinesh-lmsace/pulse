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
 * Send reminder notification to users filter by the users availability.
 *
 * @package   mod_pulse
 * @copyright 2024, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pulse\addon;

defined('MOODLE_INTERNAL') || die('No direct access !');

use mod_pulse\automation\helper;
use stdclass;

require_once($CFG->dirroot.'/mod/pulse/lib.php');

/**
 * Send reminder notification to users filter by the users availability.
 */
class notification {

    /**
     * Fetched complete record for all instances.
     *
     * @var array
     */
    private $records;

    /**
     * Module info sorted by course.
     *
     * @var mod_info|array
     */
    public $modinfo = [];

    /**
     * List of created pulse instances in LMS.
     *
     * @var array
     */
    protected $instances;

    /**
     * Fetch all pulse instance data with course and context data.
     * Each instance are set to adhoc task to send reminders.
     *
     * @param  string $additionalwhere Additional where condition to filter the pulse record
     * @param  array $additionalparams Parameters for additional where clause.
     * @return array
     */
    public function get_instances($additionalwhere='', $additionalparams=[]) {
        global $DB;

        $select[] = 'pl.id AS id'; // Set the schdule id as unique column.

        // Get columns not increase table queries.
        // ...TODO: Fetch only used columns. Fetching all the fields in a query will make double the time of query result.
        $tables = [
            'pl' => $DB->get_columns('pulse'),
            'c' => $DB->get_columns('course'),
            'ctx' => $DB->get_columns('context'),
            'cm' => array_fill_keys(['id', 'course', 'module', 'instance'], ""), // Make the values as keys.
            'md' => array_fill_keys(['id', 'name'], ""),
        ];

        foreach ($tables as $prefix => $table) {
            $columns = array_keys($table);
            // Columns.
            array_walk($columns, function(&$value, $key, $prefix) {
                $value = "$prefix.$value AS ".$prefix."_$value";
            }, $prefix);

            $select = array_merge($select, $columns);
        }

        // Number of notification to send in this que.
        $limit = get_config('pulse', 'schedulecount') ?: 100;

        // Final list of select columns, convert to sql mode.
        $select = implode(', ', $select);

        $sql = "SELECT $select
                FROM {pulse} pl
                JOIN {course} c ON c.id = pl.course
                JOIN {course_modules} cm ON cm.instance = pl.id
                JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                JOIN {modules} md ON md.id = cm.module
                WHERE md.name = 'pulse' AND cm.visible = 1 AND c.visible = 1
                AND c.startdate <= :startdate AND (c.enddate = 0 OR c.enddate >= :enddate)";

        $sql .= $additionalwhere ? ' AND '.$additionalwhere : '';

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'startdate' => time(),
            'enddate' => time(),
        ];
        $params = array_merge($params, $additionalparams);
        $this->records = $DB->get_records_sql($sql, $params);

        if (empty($this->records)) {
            pulse_mtrace('No pulse instance are added yet'."\n");
            return [];
        }
        pulse_mtrace('Fetched available pulse modules');

        foreach ($this->records as $record) {

            $instance = new stdclass();
            $instance->pulse = (object) helper::filter_record_byprefix($record, 'pl');
            $instance->course = (object) helper::filter_record_byprefix($record, 'c');
            $instance->context = (object) helper::filter_record_byprefix($record, 'ctx');
            $cm = (object) helper::filter_record_byprefix($record, 'cm');
            $instance->module = (object) helper::filter_record_byprefix($record, 'md');
            $instance->record = $record;

            if (!in_array($instance->course->id, $this->modinfo)) {
                $this->modinfo[$instance->course->id] = get_fast_modinfo($instance->course->id, 0);
            }
            $instance->modinfo = $this->modinfo[$instance->course->id];

            if (!empty($cm->id) && !empty($this->modinfo[$instance->course->id]->cms[$cm->id])) {
                $instance->cmdata = $cm;
                $instance->cm = $instance->modinfo->get_cm($cm->id);
                // Fetch list of sender users for the instance.
                $instance->sender = \mod_pulse\task\sendinvitation::get_sender($instance->course->id);

                $this->instances[$instance->pulse->id] = $instance;
            }

        }

        return $this->instances;
    }

}
