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
 * Pulse pro preset table display.
 *
 * @package   pulseaddon_preset
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../../config.php');

require_login();

// PAGE Parameters.
$action = optional_param('action', null, PARAM_TEXT);
$id = 0;
if ($action !== null) {
    if ($action != 'create') {
        $id = required_param('id', PARAM_INT);
    }

    if ($action == 'update') {
        if (!$DB->record_exists('pulse_presets', ['id' => $id])) {
            throw new moodle_exception('invalidpresetid', 'mod_pulse');
        }
    } else if ($action == 'delete') {
        // Checking the session.
        $sesskey = required_param('sesskey', PARAM_TEXT);
        if (sesskey() !== $sesskey) {
            throw new moodle_exception('sessionexpired');
        }
    }
}

$syscontext = context_system::instance();
$url = new moodle_url('/mod/pulse/addons/preset/presets.php');

$strarr = [
    'managepresets',
    'createpreset',
];

$strings = get_strings($strarr, 'pulse');

$PAGE->set_context($syscontext);
$PAGE->set_url($url);
$PAGE->set_title($strings->managepresets);
$PAGE->set_heading($strings->managepresets);

$url->params(['action' => 'create']);
$button = $OUTPUT->single_button($url, $strings->createpreset, 'get');

$PAGE->set_button($button);
$PAGE->set_pagelayout('admin');

$count = $DB->count_records('pulse_presets');
// Actions triggered from tables list. Enable/disable, Reorder presets and delete the preset.
if ($id) {
    if ($action == 'delete') {
        if ($DB->delete_records('pulse_presets', ['id' => $id])) {
            redirect($PAGE->url->out(true), get_string('presetdeleted', 'pulse'));
        }
    } else if ($action == 'up') {

        $orderno = $DB->get_field('pulse_presets', 'order_no', ['id' => $id]);
        $order = ($orderno > 1) ? $orderno - 1 : 1;

        // Update records.
        $DB->set_field('pulse_presets', 'order_no', $order, ['id' => $id]);

        // Get records.
        $records = $DB->get_records_select('pulse_presets', 'id != :id', ['id' => $id], 'order_no ASC');
        $i = 0;
        foreach ($records as $record) {
            $i++;
            if ($i == $order) {
                $i++;
            }
            $DB->set_field('pulse_presets', 'order_no', $i, ['id' => $record->id]);
        }

        redirect($PAGE->url->out(true));

    } else if ($action == 'down') {
        $orderno = $DB->get_field('pulse_presets', 'order_no', ['id' => $id]);
        $order = ($orderno < $count) ? $orderno + 1 : 1;
        $DB->set_field('pulse_presets', 'order_no', $order, ['id' => $id]);

        // Get records.
        $records = $DB->get_records_select('pulse_presets', 'id != :id', ['id' => $id], 'order_no ASC');
        $i = 0;
        foreach ($records as $record) {
            $i++;
            if ($i == $order) {
                $i++;
            }
            $DB->set_field('pulse_presets', 'order_no', $i, ['id' => $record->id]);
        }

    } else if ($action == 'enable') {
        $DB->set_field('pulse_presets', 'status', 1, ['id' => $id]);
    } else if ($action == 'disable') {
        $DB->set_field('pulse_presets', 'status', 0, ['id' => $id]);
    }
}
// Preset form class object creation.
$presetform = new \pulseaddon_preset\presets\preset_form($url, ['action' => 'update']);

// Preset create / Update form submitted.
if ($presetdata = $presetform->get_data()) {
    if (!empty($presetdata)) {

        $descriptionitemid = file_get_submitted_draft_itemid('description');;
        $instructionitemid = file_get_submitted_draft_itemid('instruction');

        $presetdata->descriptionformat = $presetdata->description['format'];
        $presetdata->description        = $presetdata->description['text'];
        $presetdata->instructionformat  = $presetdata->instruction['format'];
        $presetdata->instruction        = $presetdata->instruction['text'];
        $presetdata->configparams       = json_encode($presetdata->configparams);
        $presetdata->status             = $presetdata->status ?? 0;
        // Reorder other preset templates.
        $count = $DB->count_records('pulse_presets');
        $orderupsql = 'Update {pulse_presets} SET order_no=order_no + 1 WHERE order_no >= :order AND order_no != :count';
        $orderdownsql = 'Update {pulse_presets} SET order_no=order_no - 1 WHERE order_no <= :order AND order_no != 1';

        switch ($action) :
            // Create preset form submit.
            case "create":
                $DB->execute($orderupsql, ['order' => $presetdata->order_no, 'count' => $count]);
                if ($presetid = $DB->insert_record('pulse_presets', $presetdata)) {
                    $presetdata->description = file_save_draft_area_files(
                        $descriptionitemid, $syscontext->id, 'mod_pulse', 'description', $presetdata->id,
                        ['subdirs' => true, 'maxfiles' => 50], $presetdata->description
                    );

                    $presetdata->instruction = file_save_draft_area_files(
                        $instructionitemid, $syscontext->id, 'mod_pulse', 'instruction', $presetdata->id,
                        ['subdirs' => true, 'maxfiles' => 50], $presetdata->instruction
                    );
                    // ...TODO: Insert selected config labels with names.
                    file_save_draft_area_files(
                        $presetdata->preset_template, // Draftitemid.
                        $syscontext->id, // System context.
                        'mod_pulse', // Componenet.
                        'preset_template', // Filearea.
                        $presetid, // Itemid.
                        \pulseaddon_preset\instance::preset_fileoptions() // File manager options.
                    );
                }

                redirect($PAGE->url->out(true), get_string('presetcreated', 'pulse'));
                break;
            // Update the preset method data processing.
            case 'update':
                // Update the other presets order number.
                $previousorder = $DB->get_field('pulse_presets', 'order_no', ['id' => $presetdata->id]);
                if ($previousorder > $presetdata->order_no) {
                    // Increase the order number above than the current preset order.
                    $DB->execute($orderupsql, ['order' => $presetdata->order_no, 'count' => $count]);
                } else {
                    // Decrease the order number below than the current preset order number.
                    $DB->execute($orderdownsql, ['order' => $presetdata->order_no]);
                }
                // Update the area files of editors.
                $presetdata->description = file_save_draft_area_files(
                    $descriptionitemid, $syscontext->id, 'mod_pulse', 'description', $presetdata->id,
                    ['subdirs' => true], $presetdata->description
                );
                $presetdata->instruction = file_save_draft_area_files(
                    $instructionitemid, $syscontext->id, 'mod_pulse', 'instruction', $presetdata->id,
                    ['subdirs' => true], $presetdata->instruction
                );

                if ($DB->update_record('pulse_presets', $presetdata)) {

                    file_save_draft_area_files(
                        $presetdata->preset_template, // Draftitemid.
                        $syscontext->id, // System context.
                        'mod_pulse', // Componenet.
                        'preset_template', // Filearea.
                        $presetdata->id, // Itemid.
                        \pulseaddon_preset\instance::preset_fileoptions() // File manager options.
                    );
                }
                redirect($PAGE->url->out(true), get_string('presetupdated', 'pulse'));
                break;
        endswitch;
    }
}
// Page output starts here.
echo $OUTPUT->header();

if ($action == 'create') {

    echo $OUTPUT->heading($strings->createpreset);
    $presetform->display();

} else if ($action == 'update') {

    echo $OUTPUT->heading(get_string('update_preset', 'pulse'));
    $record = $DB->get_record('pulse_presets', ['id' => $id]);

    $draftitemid = file_get_submitted_draft_itemid('preset_template');
    file_prepare_draft_area($draftitemid, $syscontext->id, 'mod_pulse',
        'preset_template', $id, ['subdirs' => 0, 'maxfiles' => 1]);

    $record->description = [
        'text' => $record->description,
        'format' => $record->descriptionformat,
    ];
    $record->instruction = [
        'text' => $record->instruction,
        'format' => $record->instructionformat,
    ];

    $record->preset_template = $draftitemid;
    $record->action = $action;
    $record->configparams = array_values(json_decode($record->configparams, true));

    $presetform->set_data($record);
    $presetform->display();

} else {
    // Manage templates list goes here.
    echo $OUTPUT->heading(get_string('presetlist', 'pulse'));
    $presettable = new \pulseaddon_preset\presets\preset_list('list-pulse-presets');
    $presettable->set_sql('*', '{pulse_presets}', '1=1');
    $presettable->out(10, true);
}

echo $OUTPUT->footer();
