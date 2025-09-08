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
 * Report table.
 *
 * @package   pulseaddon_report
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../../config.php');
require_once($CFG->dirroot. '/lib/tablelib.php');
require_once($CFG->dirroot. '/mod/pulse/lib.php');

// Params.
$cmid = required_param('cmid', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

require_login();

$output = '';

$pageparams['cmid'] = $cmid;

$modulecontext = context_module::instance($cmid);
$cm = get_coursemodule_from_id('pulse', $cmid);

$pulse = $DB->get_record('pulse', ['id' => $cm->instance]);
$course = get_course($cm->course);

$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_context($modulecontext);
$PAGE->set_heading(get_string('reports', 'pulse'));
$downloadfilename = get_string('reportsfilename', 'pulse', ['name' => $pulse->name]);
// Participants table filterset.
$filterset = new \core_user\table\participants_filterset;
$filterset->add_filter(
    new \core_table\local\filter\integer_filter('courseid', \core_table\local\filter\filter::JOINTYPE_DEFAULT, [(int) $course->id])
);

// Approver user table - pariticipants table wrapper.
$participanttable = new \pulseaddon_report\table\report("user-index-participants-{$cm->id}");
$participanttable->define_baseurl($CFG->wwwroot . '/mod/pulse/addons/report/report.php');
$participanttable->set_filterset($filterset);
$participanttable->is_downloading($download, $downloadfilename);

$PAGE->set_url(new moodle_url('/mod/pulse/addons/report/report.php', $pageparams));

require_capability('pulseaddons/report:viewreports', $PAGE->context, $USER->id);

// Page header output.
if (!$participanttable->is_downloading()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($pulse->name));
    // List of available participants table output.
    echo $output;
}

if (isset($participanttable)) {
    $pagesize = $participanttable->is_downloading() ? 0 : 10;
    echo $participanttable->out($pagesize, true);
}

if (!$participanttable->is_downloading()) {
    // Page footer output.
    echo $OUTPUT->footer();
}
