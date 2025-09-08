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
 * Class used to fetch participants based on a filterset. Updated version of moodle core user search class.
 *
 * @package   pulseaddon_report
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_report\table;

use context;
use context_helper;
use core_table\local\filter\filterset;
use core_user;
use moodle_recordset;
use stdClass;
use user_picture;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/user/lib.php');

/**
 * Class used to fetch participants based on a filterset.
 **/
class report_search extends \core_user\table\participants_search {

    /**
     * @var filterset $filterset The filterset describing which participants to include in the search.
     */
    protected $filterset;

    /**
     * @var stdClass $course The course being searched.
     */
    protected $course;

    /**
     * @var \context_course $context The course context being searched.
     */
    protected $context;

    /**
     * @var string[] $userfields Names of any extra user fields to be shown when listing users.
     */
    protected $userfields;

    /**
     * Pulse instance data
     *
     * @var stdclass
     */
    public $pulse;

    /**
     * Course module info.
     *
     * @var stdclass
     */
    public $cm;

    /**
     * Class constructor.
     *
     * @param stdClass $course The course being searched.
     * @param context $context The context of the search.
     * @param filterset $filterset The filterset used to filter the participants in a course.
     * @param int $instanceid pulse instnace id.
     */
    public function __construct(stdClass $course, context $context, filterset $filterset, int $instanceid) {
        global $PAGE, $DB;
        parent::__construct($course, $context, $filterset);
        $this->pulse = $DB->get_record('pulse', ['id' => $instanceid]);
        $this->cm = $PAGE->cm;
    }

    /**
     * Prepare SQL and associated parameters for users enrolled in the course.
     *
     * @return array SQL query data in the format ['sql' => '', 'forcedsql' => '', 'params' => []].
     */
    protected function get_enrolled_sql(): array {
        global $USER;

        $isfrontpage = ($this->context->instanceid == SITEID);
        $prefix = 'eu_';
        $filteruid = "{$prefix}u.id";
        $sql = '';
        $joins = [];
        $wheres = [];
        $params = [];
        // It is possible some statements must always be included (in addition to any filtering).
        $forcedprefix = "f{$prefix}";
        $forceduid = "{$forcedprefix}u.id";
        $forcedsql = '';
        $forcedjoins = [];
        $forcedwhere = "{$forcedprefix}u.deleted = 0";
        $groupids = [];

        if ($this->filterset->has_filter('groups')) {
            $groupids = $this->filterset->get_filter('groups')->get_filter_values();
        }

        // Force additional groups filtering if required due to lack of capabilities.
        // Note: This means results will always be limited to allowed groups, even if the user applies their own groups filtering.

        $canaccessallgroups = (has_capability('moodle/site:accessallgroups', $this->context)
                                    || \mod_pulse\helper::pulse_isusercontext($this->pulse, $this->cm->id));
        $forcegroups = ($this->course->groupmode == SEPARATEGROUPS && !$canaccessallgroups);

        if ($forcegroups) {
            $allowedgroupids = array_keys(groups_get_all_groups($this->course->id, $USER->id));

            // Users not in any group in a course with separate groups mode should not be able to access the participants filter.
            if (empty($allowedgroupids)) {
                // The UI does not support this, so it should not be reachable unless someone is trying to bypass the restriction.
                throw new \coding_exception('User must be part of a group to filter by participants.');
            }

            $forceduid = "{$forcedprefix}u.id";
            $forcedjointype = $this->get_groups_jointype(\core_table\local\filter\filter::JOINTYPE_ANY);
            $forcedgroupjoin = groups_get_members_join($allowedgroupids, $forceduid, $this->context, $forcedjointype);

            $forcedjoins[] = $forcedgroupjoin->joins;
            $forcedwhere .= " AND ({$forcedgroupjoin->wheres})";

            $params = array_merge($params, $forcedgroupjoin->params);

            // Remove any filtered groups the user does not have access to.
            $groupids = array_intersect($allowedgroupids, $groupids);
        }

        // Prepare any user defined groups filtering.
        if ($groupids) {
            $groupjoin = groups_get_members_join($groupids, $filteruid, $this->context, $this->get_groups_jointype());

            $joins[] = $groupjoin->joins;
            $params = array_merge($params, $groupjoin->params);
            if (!empty($groupjoin->wheres)) {
                $wheres[] = $groupjoin->wheres;
            }
        }

        // Combine the relevant filters and prepare the query.
        $joins = array_filter($joins);
        if (!empty($joins)) {
            $joinsql = implode("\n", $joins);

            $sql = "SELECT DISTINCT {$prefix}u.id
                    FROM {user} {$prefix}u
                    {$joinsql}
                    WHERE {$prefix}u.deleted = 0";
        }

        $wheres = array_filter($wheres);
        if (!empty($wheres)) {
            if ($this->filterset->get_join_type() === $this->filterset::JOINTYPE_ALL) {
                $wheresql = '(' . implode(') AND (', $wheres) . ')';
            } else {
                $wheresql = '(' . implode(') OR (', $wheres) . ')';
            }

            $sql .= " AND ({$wheresql})";
        }

        // Prepare any SQL that must be applied.
        if (!empty($forcedjoins)) {
            $forcedjoinsql = implode("\n", $forcedjoins);
            $forcedsql = "SELECT DISTINCT {$forcedprefix}u.id
                                     FROM {user} {$forcedprefix}u
                                          {$forcedjoinsql}
                                    WHERE {$forcedwhere}";
        }

        return [
            'sql' => $sql,
            'forcedsql' => $forcedsql,
            'params' => $params,
        ];
    }

    /**
     * Generate the SQL used to fetch filtered data for the participants table.
     *
     * @param string $additionalwhere Any additional SQL to add to where
     * @param array $additionalparams The additional params
     * @return array
     */
    protected function get_participants_sql(string $additionalwhere, array $additionalparams): array {
        global $DB;

        $isfrontpage = ($this->course->id == SITEID);
        $accesssince = 0;
        // Whether to match on users who HAVE accessed since the given time (ie false is 'inactive for more than x').
        $matchaccesssince = false;

        // The alias for the subquery that fetches all distinct course users.
        $usersubqueryalias = 'targetusers';
        // The alias for {user} within the distinct user subquery.
        $inneruseralias = 'udistinct';
        // Inner query that selects distinct users in a course who are not deleted.
        // Note: This ensures the outer (filtering) query joins on distinct users, avoiding the need for GROUP BY.
        $innerselect = "SELECT DISTINCT {$inneruseralias}.id";
        $innerjoins = ["{user} {$inneruseralias}"];
        $innerwhere = "WHERE {$inneruseralias}.deleted = 0";

        $outerjoins = ["JOIN {user} u ON u.id = {$usersubqueryalias}.id"];
        $wheres = [];

        if ($this->filterset->has_filter('accesssince')) {
            $accesssince = $this->filterset->get_filter('accesssince')->current();

            // Last access filtering only supports matching or not matching, not any/all/none.
            $jointypenone = $this->filterset->get_filter('accesssince')::JOINTYPE_NONE;
            if ($this->filterset->get_filter('accesssince')->get_join_type() === $jointypenone) {
                $matchaccesssince = true;
            }
        }

        [
            // SQL that forms part of the filter.
            'sql' => $esql,
            // SQL for enrolment filtering that must always be applied (eg due to capability restrictions).
            'forcedsql' => $esqlforced,
            'params' => $params,
        ] = $this->get_enrolled_sql();

        // Get the fields for all contexts because there is a special case later where it allows
        // matches of fields you can't access if they are on your own account.
        if (class_exists('\core_user\fields')) {
            $userfields = \core_user\fields::for_identity(null)->with_userpic();
            ['selects' => $userfieldssql, 'joins' => $userfieldsjoin, 'params' => $userfieldsparams, 'mappings' => $mappings] =
                    (array)$userfields->get_sql('u', true);
            if ($userfieldsjoin) {
                $outerjoins[] = $userfieldsjoin;
                $params = array_merge($params, $userfieldsparams);
            }
        } else {
            $userfieldssql = ', '.user_picture::fields('u', $this->userfields);
        }

        // Include any compulsory enrolment SQL (eg capability related filtering that must be applied).
        if (!empty($esqlforced)) {
            $outerjoins[] = "JOIN ({$esqlforced}) fef ON fef.id = u.id";
        }
        // Include any enrolment related filtering.
        if (!empty($esql)) {
            $outerjoins[] = "LEFT JOIN ({$esql}) ef ON ef.id = u.id";
            $wheres[] = 'ef.id IS NOT NULL';
        }

        if ($isfrontpage) {
            $outerselect = "SELECT u.lastaccess $userfieldssql";
            if ($accesssince) {
                $wheres[] = user_get_user_lastaccess_sql($accesssince, 'u', $matchaccesssince);
            }
        } else {
            $outerselect = "SELECT COALESCE(ul.timeaccess, 0) AS lastaccess $userfieldssql";
            // Not everybody has accessed the course yet.
            $outerjoins[] = 'LEFT JOIN {user_lastaccess} ul ON (ul.userid = u.id AND ul.courseid = :courseid2)';
            $params['courseid2'] = $this->course->id;
            if ($accesssince) {
                $wheres[] = user_get_course_lastaccess_sql($accesssince, 'ul', $matchaccesssince);
            }

            // Make sure we only ever fetch users in the course (regardless of enrolment filters).
            $innerjoins[] = "JOIN {user_enrolments} ue ON ue.userid = {$inneruseralias}.id";
            $innerjoins[] = 'JOIN {enrol} e ON e.id = ue.enrolid
                                      AND e.courseid = :courseid1';
            $params['courseid1'] = $this->course->id;
        }

        // Performance hacks - we preload user contexts together with accounts.
        $ccselect = ', ' . context_helper::get_preload_record_columns_sql('ctx');
        $ccjoin = 'LEFT JOIN {context} ctx ON (ctx.instanceid = u.id AND ctx.contextlevel = :contextlevel)';
        $params['contextlevel'] = CONTEXT_USER;
        $outerselect .= $ccselect;
        $outerjoins[] = $ccjoin;

        // JOIN the pulse notification result.
        $outerjoins[] = "LEFT JOIN {pulse} pl ON pl.id = :pulseid";
        $params['pulseid'] = $this->pulse->id;
        $outerselect .= ', pl.id as pulseid, '.$this->pulse_fields();

        // JOIN the user completions.
        $outerjoins[] = "LEFT JOIN {pulse_completion} pc ON pc.userid=u.id AND pc.pulseid = pl.id";

        $outerselect .= ', '.$this->pulsecompletion_fields();

        $outerjoins[] = "LEFT JOIN {pulse_users} plu ON plu.userid=u.id AND plu.pulseid = pl.id AND plu.status=1";
        $params['pulseid3'] = $this->pulse->id;
        $outerselect .= ', plu.timecreated as invitation_reminder_time';

        // Join the pulse reactions table.
        $reactiontype = \pulseaddon_reaction\instance::REACTION_RATE;
        $outerjoins[] = "LEFT JOIN {pulseaddon_reaction_tokens} prt ON prt.userid = u.id
                        AND prt.pulseid = pl.id
                        AND prt.status > 0
                        AND prt.reactiontype = $reactiontype";

        $outerselect .= ', prt.status AS reaction';

        // JOIN the pulseaddon_reminder_notified table to fetch reminder times.
        $outerjoins[] = "LEFT JOIN {pulseaddon_reminder_notified} prn_first ON prn_first.userid = u.id
                        AND prn_first.pulseid = pl.id
                        AND prn_first.reminder_type = 'first'
                        AND prn_first.reminder_status = 1";

        $outerjoins[] = "LEFT JOIN {pulseaddon_reminder_notified} prn_second ON prn_second.userid = u.id
                        AND prn_second.pulseid = pl.id
                        AND prn_second.reminder_type = 'second'
                        AND prn_second.reminder_status = 1";

        $outerjoins[] = "LEFT JOIN (
                            SELECT prn.userid, prn.pulseid, MAX(prn.reminder_time) as reminder_time
                            FROM {pulseaddon_reminder_notified} prn
                            WHERE prn.reminder_type = 'recurring' AND prn.reminder_status = 1
                            GROUP BY prn.userid, prn.pulseid ORDER BY MAX(prn.id) DESC
                        ) prn_recurring ON prn_recurring.userid = u.id AND prn_recurring.pulseid = pl.id";

        $outerselect .= ', prn_first.reminder_time AS first_reminder_time';
        $outerselect .= ', prn_second.reminder_time AS second_reminder_time';
        $outerselect .= ', prn_recurring.reminder_time AS recurring_reminder_time';

        $outerjoins[] = "LEFT JOIN (
                            SELECT prn.userid, prn.pulseid,
                            " . $DB->sql_group_concat("prn.reminder_time", ',') . " AS recurring_reminder_prevtime
                            FROM {pulseaddon_reminder_notified} prn
                            WHERE prn.reminder_type = 'recurring' AND prn.reminder_status = 1
                            GROUP BY prn.userid, prn.pulseid ORDER BY MAX(prn.id) DESC
                        ) prn_prev_recurring ON prn_prev_recurring.userid = u.id AND prn_prev_recurring.pulseid = pl.id";

        $outerselect .= ', prn_prev_recurring.recurring_reminder_prevtime AS recurring_reminder_prevtime';

        // Add any supplied additional forced WHERE clauses.
        if (!empty($additionalwhere)) {
            $innerwhere .= " AND ({$additionalwhere})";
            $params = array_merge($params, $additionalparams);
        }

        // Prepare final values.
        $outerjoinsstring = implode("\n", $outerjoins);
        $innerjoinsstring = implode("\n", $innerjoins);
        if ($wheres) {
            switch ($this->filterset->get_join_type()) {
                case $this->filterset::JOINTYPE_ALL:
                    $wherenot = '';
                    $wheresjoin = ' AND ';
                    break;
                case $this->filterset::JOINTYPE_NONE:
                    $wherenot = ' NOT ';
                    $wheresjoin = ' AND NOT ';

                    // Some of the $where conditions may begin with `NOT` which results in `AND NOT NOT ...`.
                    // To prevent this from breaking on Oracle the inner WHERE clause is wrapped in brackets, making it
                    // `AND NOT (NOT ...)` which is valid in all DBs.
                    $wheres = array_map(function($where) {
                        return "({$where})";
                    }, $wheres);

                    break;
                default:
                    // Default to 'Any' jointype.
                    $wherenot = '';
                    $wheresjoin = ' OR ';
                    break;
            }

            $outerwhere = 'WHERE ' . $wherenot . implode($wheresjoin, $wheres);
        } else {
            $outerwhere = '';
        }
        return [
            'subqueryalias' => $usersubqueryalias,
            'outerselect' => $outerselect,
            'innerselect' => $innerselect,
            'outerjoins' => $outerjoinsstring,
            'innerjoins' => $innerjoinsstring,
            'outerwhere' => $outerwhere,
            'innerwhere' => $innerwhere,
            'params' => $params,
        ];
    }

    /**
     * Pulse fileds wanted to fetch from pusle table.
     *
     * @return array list of wanted pulse table fields
     */
    public function pulse_fields() {
        $fields = [
            'pl.completionavailable',
            'pl.completionself',
            'pl.completionapproval',
            'pl.completionapprovalroles',
        ];
        return implode(',', $fields);
    }

    /**
     * Table pulse_availability, fields wanted to fetch are defined.
     *
     * @return array list of availability fields.
     */
    public function pulseavailability_fields() {
        $fields = 'ppa.status,
        ppa.availabletime,
        ppa.first_reminder_status,
        ppa.second_reminder_status,
        ppa.recurring_reminder_prevtime,
        ppa.first_reminder_time,
        ppa.second_reminder_time,
        ppa.recurring_reminder_time
        ';
        return $fields;
    }

    /**
     * Needed pulse completion table fields are deifined.
     *
     * @return array list of completion fields.
     */
    public function pulsecompletion_fields() {
        $fields = [
            'pc.approvalstatus',
            'pc.approveduser',
            'pc.selfcompletion',
            'pc.selfcompletiontime',
        ];

        return implode(',', $fields);
    }
}
