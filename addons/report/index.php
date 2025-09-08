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
 * List of pulse instance in course.
 *
 * @package   pulseaddon_report
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../../../config.php');

require_login();

$courseid = required_param('id', PARAM_INT);
$pageurl = new moodle_url('/mod/pulse/addons/report/index.php', ['id' => $courseid]);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($course->id);

$PAGE->set_context($coursecontext);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_url($pageurl);
$PAGE->set_course($course);

echo $OUTPUT->header();

if (class_exists('core\report_helper')) {
    core\report_helper::print_report_selector(get_string('reports', 'pulse'));
} else {
    echo $OUTPUT->heading(get_string('reports', 'pulse'));
}

// List of instance added in the course.
$instancelist = pulseaddon_report\instance::get_course_instancelist($courseid);
if (empty($instancelist)) {
    notice(get_string('pulsenotavailable', 'pulse'));
}

$table = new html_table();
$table->attributes['class'] = 'generaltable pulse-instance-list';
$table->head = [get_string('name'), ''];

$modules = [];
foreach ($instancelist as $key => $value) {
    $data = [];
    $moduleurl = new moodle_url('/mod/pulse/view.php', ['id' => $value->id]);
    $reports = new moodle_url('/mod/pulse/addons/report/report.php', ['cmid' => $value->id]);
    $data[] = html_writer::link($moduleurl, format_string($value->name), ['class' => 'pulse-instance-name']);
    $data[] = html_writer::link($reports, get_string('viewreport', 'pulse'));
    $table->data[] = $data;
}
// Table.
echo html_writer::table($table);

echo $OUTPUT->footer();
