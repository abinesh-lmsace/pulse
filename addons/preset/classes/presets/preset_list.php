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
 * Pulse pro list of presets table.
 *
 * @package   pulseaddon_preset
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_preset\presets;

use core_table\dynamic;
use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();

// Table lib included.
require_once($CFG->libdir . '/tablelib.php');

/**
 * Created list of presets sql class with options to update visbility and rearrange order.
 */
class preset_list extends \table_sql implements dynamic {

    /**
     * Helps to hide the down order arraow for last element, up arrow for first element
     *
     * @var int
     */
    protected $updowncount;

    /**
     * Count of presets list.
     *
     * @var int
     */
    public $count;

    /**
     * Define table headers and columns, default order column.
     *
     * @param string $uniqueid a string identifying this table.Used as a key in
     *                          session  vars.
     */
    public function __construct($uniqueid) {

        parent::__construct($uniqueid);

        $columns = ['title', 'description', 'configparams', 'preset_template', 'status', 'order_no', 'action'];
        $headers = [
            get_string('title', 'pulse'),
            get_string('description'),
            get_string('configparams', 'pulse'),
            get_string('preset_template', 'pulse'),
            get_string('status'),
            get_string('order'),
            get_string('action'),
        ];

        $this->define_columns($columns);
        $this->define_headers($headers);

        $this->no_sorting('action');

        $this->sort_default_column = 'order_no';
        $this->sort_default_order  = SORT_ASC;

        $this->baseurl = new moodle_url('/mod/pulse/addons/preset/presets.php');
        $this->updowncount = 1;
    }

    /**
     * Need to find the count of records inserted in preset. Used to remove the down arrow in last row for order column.
     *
     * @param int $pagesize size of page for paginated displayed table.
     * @param bool $useinitialsbar do you want to use the initials bar.
     * @return void
     */
    public function query_db($pagesize, $useinitialsbar=true) {
        global $DB;
        parent::query_db($pagesize, $useinitialsbar);
        $this->count = $DB->count_records_sql($this->countsql, $this->countparams);
    }

    /**
     * Update the file url in Description content to display on list.
     *
     * @param stdclass $row Preset data object
     * @return string Description content
     */
    public function col_description($row) {
        $description = file_rewrite_pluginfile_urls(
            $row->description, 'pluginfile.php', \context_system::instance()->id, 'mod_pulse', 'description', $row->id
        );
        return $description;
    }

    /**
     * Update the file url in instruction content to display on list.
     *
     * @param stdclass $row Preset data object
     * @return string Instruction content
     */
    public function col_instruction($row): string {
        $instruction = file_rewrite_pluginfile_urls(
            $row->instruction, 'pluginfile.php', \context_system::instance()->id, 'mod_pulse', 'instruction', $row->id
        );
        return $instruction;
    }

    /**
     * Add icons to change the order of preset. User can able to up/down the order of preset.
     *
     * @param stdclass $row Preset record data.
     * @return string $updown Link to rearrange the order of preset.
     */
    public function col_order_no($row) {
        global $OUTPUT;
        $updown = '';

        if ($this->updowncount > 1) {
            $url = new \moodle_url("/mod/pulse/addons/preset/presets.php", ['action' => 'up', 'id' => $row->id]);
            $updown .= html_writer::link($url, $OUTPUT->pix_icon('t/up', get_string('moveup')));
        } else {
            $updown .= $OUTPUT->spacer() . '&nbsp;';
        }

        if ($this->updowncount < ($this->count)) {
            $url = new \moodle_url("/mod/pulse/addons/preset/presets.php", ['action' => 'down', 'id' => $row->id]);
            $updown .= html_writer::link($url, $OUTPUT->pix_icon('t/down', get_string('movedown')));
        } else {
            $updown .= $OUTPUT->spacer() . '&nbsp;';
        }
        ++ $this->updowncount;
        return $updown;

    }

    /**
     * Action column. edit or delete the istance.
     *
     * @param stdclass $row
     * @return string
     */
    public function col_action(\stdclass $row): string {
        global $OUTPUT;

        $editurl = new moodle_url('/mod/pulse/addons/preset/presets.php', ['action' => 'update', 'id' => $row->id]);
        $deleteurl = new moodle_url('/mod/pulse/addons/preset/presets.php', ['action' => 'delete', 'id' => $row->id]);
        $html = html_writer::link($editurl, html_writer::tag('i', '', ['class' => 'fa fa-gear']), ['class' => 'edit-template'] );

        // Delete with popup confirmation.
        $url = new moodle_url($this->baseurl, ['action' => 'delete', 'id' => $row->id, 'sesskey' => sesskey()]);
        $deleteaction = new \confirm_action(get_string('confirmdeletepreset', 'pulse'));
        $html .= $OUTPUT->action_icon($url, new \pix_icon('t/delete', get_string('deletepreset', 'pulse')), $deleteaction);
        return $html;
    }

    /**
     * Action column. edit or delete the istance.
     *
     * @param stdclass $row
     * @return string
     */
    public function col_configparams(\stdclass $row): string {
        $params = json_decode($row->configparams, true);
        $html = '';
        $configlist = \pulseaddon_preset\presets\preset_form::get_pulse_config_list(1);

        if (!empty($params)) {
            $html .= html_writer::start_tag('div', ['class' => 'preset-config-params']);
            foreach ($params as $value) {
                $param = (isset($configlist[$value])) ? $configlist[$value] : $value;
                $html .= html_writer::span($param, 'config-param');
            }
            $html .= html_writer::end_div();
        }
        return $html;
    }

    /**
     * Status of the preset template - enable/disable
     *
     * @param \stdclass $row Preset data row.
     * @return string $html
     */
    public function col_status(\stdclass $row): string {
        $status = $row->status;
        $html = ($status) ?
                html_writer::link(new moodle_url('/mod/pulse/addons/preset/presets.php', ['id' => $row->id, 'action' => 'disable']),
                    html_writer::span(get_string('enabled', 'pulse'), 'badge badge-success')) :
                html_writer::link(new moodle_url('/mod/pulse/addons/preset/presets.php', ['id' => $row->id, 'action' => 'enable']),
                        html_writer::span(get_string('disabled', 'pulse'), 'badge badge-danger'));
        return $html;
    }

    /**
     * Display the preset template file link to download on list.
     *
     * @param stdclass $row Record of preset row.
     * @return string Link to download the preset template.
     */
    public function col_preset_template($row): string {
        global $PAGE;

        $html = '';
        $fs = get_file_storage();
        $files = $fs->get_area_files($PAGE->context->id, 'mod_pulse', 'preset_template', $row->id, '', false);
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $url = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename(),
                false
            );
            $html .= html_writer::link($url, $filename, ['class' => 'preset-file-template']);
        }
        return $html;
    }

    /**
     * Check if the user has the capability to access this table.
     *
     * @return bool Return true if capability check passed.
     */
    public function has_capability(): bool {
        return has_capability('moodle/site:config', \context_system::instance());
    }

}
