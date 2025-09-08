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
 * Reminders and reactions user reports table.
 *
 * @package   pulseaddon_report
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_report\table;

defined('MOODLE_INTERNAL') || die;

use html_writer;
use mod_pulse\extendpro;
use moodle_url;
use PhpParser\Builder\Method;

require_once($CFG->dirroot. '/mod/pulse/lib.php');

/**
 * User reminder status and reactions report and activity completions reports table.
 */
class report extends \core_user\table\participants {

    /**
     * Current pulse instance record data.
     *
     * @var stdclass
     */
    public $pulse;

    /**
     * Pulse pro data for Current pulse instance.
     *
     * @var stdclass
     */
    public $pulseaddon;

    /**
     * Approved users list.
     *
     * @var array
     */
    protected $completionusers = [];

    /**
     * Course module instance.
     *
     * @var cm_info
     */
    protected $cm;

    /**
     * Pulse module available user reactions.
     *
     * @var array
     */
    public $pulsereactions;

    /**
     * Callbacks for the additional columns.
     *
     * @var [type]
     */
    protected $callbacks;

    /**
     * Options of the pulse addon.
     *
     * @var array
     */
    public $options = [];

    /**
     * Fetch completions users list.
     *
     * @param  int $tableid
     * @return void
     */
    public function __construct($tableid) {
        global $PAGE, $DB;
        parent::__construct($tableid);
        // Page doesn't set when called via dynamic table.
        // Fix this use the cmid from table unique id.
        if (empty($PAGE->cm)) {
            $expuniqueid = explode('-', $tableid);
            $cmid = (int) end($expuniqueid);
            $this->cm = get_coursemodule_from_id('pulse', $cmid);
        } else {
            $this->cm = $PAGE->cm;
        }
        $this->pulse = $DB->get_record('pulse', ['id' => $this->cm->instance]);
        $this->pulseaddon = $DB->get_record('pulseaddon_reminder', ['pulseid' => $this->cm->instance]);

        $pulseoptions = $DB->get_records('pulse_options', ['pulseid' => $this->cm->instance]);
        foreach ($pulseoptions as $option) {
            $this->options[$option->name] = $option->value;
        }

        // Set download option to reports.
        $this->downloadable = true;
        $this->showdownloadbuttonsat = [TABLE_P_BOTTOM];
    }

    /**
     * Table header and columns definition.
     *
     * @param  int $pagesize Number of rows in a page.
     * @param  bool $useinitialsbar pagination bars.
     * @param  string $downloadhelpbutton
     * @return void
     */
    public function out($pagesize, $useinitialsbar, $downloadhelpbutton = '') {
        global $CFG, $OUTPUT, $PAGE;
        // Define the headers and columns.
        $headers = [];
        $columns = [];
        $headers[] = get_string('fullname');
        $columns[] = 'fullname';
        // Add column for groups if the user can view them.
        $canseegroups = !isset($hiddenfields['groups']);
        // Do not show the columns if it exists in the hiddenfields array.
        if (!isset($hiddenfields['lastaccess'])) {
            if ($this->courseid == SITEID) {
                $headers[] = get_string('lastsiteaccess');
            } else {
                $headers[] = get_string('lastcourseaccess');
            }
            $columns[] = 'lastaccess';
        }

        $columns[] = 'invitation_reminder_time';
        $headers[] = get_string('invitation', 'mod_pulse');

        $callbacks = [];
        extendpro::report_add_columns($headers, $columns, $callbacks);

        $this->callbacks = $callbacks;

        $columns[] = 'completioncriteria';
        $headers[] = get_string('completioncriteria', 'mod_pulse');
        $this->no_sorting('completioncriteria');

        $this->define_columns($columns);
        $this->define_headers($headers);
        // The name column is a header.
        $this->define_header_column('fullname');
        // Make this table sorted by last name by default.
        $this->sortable(true, 'lastname');
        $this->set_attribute('id', 'participants');

        $this->extrafields = [];
        \table_sql::out($pagesize, $useinitialsbar, $downloadhelpbutton);
    }

    /**
     * Generate the fullname column.
     *
     * @param \stdClass $data
     * @return string
     */
    public function col_fullname($data) {
        global $OUTPUT;

        if ($this->is_downloading()) {
            return fullname($data);
        }
        return $OUTPUT->user_picture($data, ['size' => 35, 'courseid' => $this->course->id, 'includefullname' => true]);
    }

    /**
     * Time of Invitation reminder send to user, with previous invitation reminders time.
     *
     * @param  stdclass $row User reminder data.
     * @return string time and previous invitation times in userdate format
     */
    public function col_invitation_reminder_time($row) {
        $date = ($row->invitation_reminder_time != '') ?
            userdate($row->invitation_reminder_time, get_string('strftimedatetimeshort', 'core_langconfig')) : '';

        $previoustimes = $this->get_invitation_previous_time($row);
        if ($previoustimes != '') {
            $date .= (!empty($previoustimes) && !$this->is_downloading()) ?
                html_writer::tag('label', get_string('previousreminders', 'pulse'), ['class' => 'previous-reminders']) : " ";
            foreach ($previoustimes as $time) {
                $usertime = userdate($time, get_string('strftimedatetimeshort', 'core_langconfig'));
                if (!$this->is_downloading()) {
                    $date .= html_writer::span($usertime, 'invitation-list');
                }
            }
        }
        return $date;
    }

    /**
     * Get list of previous invitation times.
     *
     * @param  stdclass $row Table current row data object.
     * @return array $records Previous reminder records.
     */
    public function get_invitation_previous_time($row) {
        global $DB;
        $sql = "SELECT pu.timecreated
                FROM {pulse_users} pu
                WHERE pu.pulseid=:pulseid AND pu.userid=:userid AND pu.status=0
                ORDER BY pu.timecreated DESC";
        $records = $DB->get_records_sql($sql, ['pulseid' => $row->pulseid, 'userid' => $row->id]);
        $timecreated = array_column($records, 'timecreated');
        return $timecreated;
    }

    /**
     * Processes a specific column for a given row using a callback function if available.
     *
     * @param string $column The name of the column to process.
     * @param object $row The data row to process.
     * @return mixed The result of the callback function if callable, otherwise an empty string.
     */
    public function other_cols($column, $row) {

        if (isset($this->callbacks[$column])) {

            return is_callable($this->callbacks[$column]) ? $this->callbacks[$column]($row, $this) : '';
        } else {
            echo '-';
        }
    }

    /**
     * Column displays the type of reaction users react.
     *
     * @param  stdclass $row User pulse addon availability data.
     * @return string Reaction status.
     */
    public function col_reaction($row) {

        if ($row->reaction != 0) {
            if ($row->reaction == 2) {
                $str = get_string('like', 'mod_pulse');
                return ($this->is_downloading()) ?
                    $str : '<label class="badge badge-success" > <span class="fa fa-thumbs-up">'.$str.'</span></label>';
            } else {
                $str = get_string('dislike', 'mod_pulse');
                return ($this->is_downloading()) ?
                    $str : '<label class="badge badge-danger" > <span class="fa fa-thumbs-down">'.$str.'</span></label>';
            }
        }
    }

    /**
     * List of selected completion criteria for the pulse instance.
     * With user completion status.
     *
     * @param  stdclass $row User pulse addon availability data.
     * @return string Completion criteria and user completion status.
     */
    public function col_completioncriteria($row) {

        if ($this->is_downloading()) {
            $result[] = $this->completionself($row);
            $result[] = $this->completionapproval($row);
            return implode(', ', array_filter($result));
        } else {
            $result = $this->completionself($row);
            $result .= $this->completionapproval($row);
            if (isset($this->options['reactiontype']) && $this->options['reactiontype'] && !$this->is_downloading()) {
                $reaction = (isset($row->reaction) && !empty($row->reaction)) ?
                    'badge badge-success' : 'badge badge-secondary';
                $result .= '<label class="reaction '. $reaction .'"> <span class="fa fa-bolt"></span>';
                $result .= get_string('reaction', 'mod_pulse').'</label>';
            }
        }
        return $result;
    }

    /**
     * Completion by self if user completes the instance it displays the completed time.
     *
     * @param  stdclass $row User pulse addon availability data.
     * @return string Compeltion self time.
     */
    public function completionself($row) {
        if ($row->completionself == true) {
            if ($this->is_downloading()) {
                return ($row->selfcompletion) ? get_string('self', 'mod_pulse') : '';
            }
            $self = ($row->selfcompletion) ? 'badge badge-success' : 'badge badge-secondary';
            $result = '<label class="self-completion '.$self.'">
                <span class="fa fa-check"></span> '.get_string('self', 'mod_pulse').'</label>';
        }
        return (isset($result)) ? $result : '';
    }

    /**
     * completion approval roles selected in the instance.
     *
     * @param  stdclass $row User pulse addon availability data.
     * @return string Selected completion roles or approved user role.
     */
    public function completionapproval($row) {
        global $DB;
        if ($row->completionapproval == true) {
            $approvalroles = json_decode($row->completionapprovalroles);
            if ($row->approvalstatus) {
                $approval = ($row->approvalstatus) ? 'badge badge-success' : 'badge badge-secondary';
                $approveduser = $row->approveduser;
                if (!empty($approveduser)) {
                    $modulecontext = \context_module::instance($this->cm->id);
                    $roles = get_user_roles($modulecontext, $approveduser);
                    // User content roles.
                    $usercontext = \context_user::instance($row->id);
                    $usercontextroles = get_user_roles($usercontext, $approveduser);
                    $roles = array_merge($roles, $usercontextroles);
                    role_fix_names($roles); // Fix role names.
                    foreach ($roles as $key => $role) {
                        if (in_array($role->roleid, $approvalroles)) {
                            $approvedrole = $role;
                            break;
                        }
                    }

                    if (isset($approvedrole)) {
                        $approvedrolename = (isset($approvedrole->localname)) ? $approvedrole->localname : '';
                        if ($this->is_downloading()) {
                            return $approvedrolename;
                        }
                        $result = '<label class="approved-completion '. $approval .'">
                            <span class="fa fa-check-square-o"></span> '.$approvedrolename.'</label>';
                    }

                }
            } else {
                $result = '';
                list($insql, $inparams) = $DB->get_in_or_equal($approvalroles);
                $sql = "SELECT * from {role} WHERE id $insql";
                $roles = $DB->get_records_sql($sql, $inparams);
                $rolenames = role_fix_names($roles);
                foreach ($rolenames as $key => $role) {
                    $result .= '<label class="approved-completion badge badge-secondary">
                        <span class="fa fa-check-square-o"></span> '.$role->localname.'</label>';
                }
            }
        }
        return (isset($result) && !$this->is_downloading()) ? $result : '';
    }

    /**
     * Guess the base url for the participants table.
     */
    public function guess_base_url(): void {
        $this->baseurl = new \moodle_url('/mod/pulse/addons/report/report.php', ['cmid' => $this->cm->id]);
    }

    /**
     * Query the database for results to display in the table.
     *
     * @param int $pagesize size of page for paginated displayed table.
     * @param bool $useinitialsbar do you want to use the initials bar.
     */
    public function query_db($pagesize, $useinitialsbar = true) {

        list($twhere, $tparams) = $this->get_sql_where();

        $psearch = new report_search($this->course, $this->context, $this->filterset, $this->cm->instance);

        $sort = $this->get_sql_sort();
        if ($sort && !method_exists('moodle_database', 'get_counted_records_sql')) {
            $sort = 'ORDER BY ' . $sort;
            // Add filter for user context assigned users.
            $total = $psearch->get_total_participants_count($twhere, $tparams);

        }
        $this->use_pages = true;
        $rawdata = $psearch->get_participants($twhere, $tparams, $sort, $this->get_page_start(), $this->get_page_size());
        $total = $total ?? $rawdata->current()->fullcount ?? 0;

        $this->pagesize($pagesize, $total);

        $this->rawdata = [];
        foreach ($rawdata as $user) {
            $this->rawdata[$user->id] = $user;
        }
        $rawdata->close();

        if ($this->rawdata) {
            $this->allroleassignments = get_users_roles($this->context, array_keys($this->rawdata),
                    true, 'c.contextlevel DESC, r.sortorder ASC');
        } else {
            $this->allroleassignments = [];
        }

        // Set initial bars.
        if ($useinitialsbar) {
            $this->initialbars(true);
        }
    }
}
