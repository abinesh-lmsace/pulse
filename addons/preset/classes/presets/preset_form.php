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
 * Pulse pro preset create / update form.
 *
 * @package   pulseaddon_preset
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_preset\presets;

defined( 'MOODLE_INTERNAL') || die(' No direct access ');

require_once($CFG->libdir.'/formslib.php');

// Prevent direct access.
defined('MOODLE_INTERNAL') || die("No direct access!");

/**
 * Preset create / update form definition.
 */
class preset_form extends \moodleform {

    /**
     * Preset create or edit templates form elements defined here.
     *
     * @return void
     */
    public function definition(): void {
        global $CFG, $PAGE;

        // Moodle form object.
        $mform = $this->_form;

        $mform->updateAttributes(['class' => 'create-preset-form mform']);
        // Fetch list of useful strings.
        $strings = $this->get_strings();

        // Preset template id.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Preset Title.
        $mform->addElement('text', 'title', $strings->title);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('required'), 'required');

        // Description.
        $mform->addElement('editor', 'description', $strings->description, '',
            ['maxfiles' => EDITOR_UNLIMITED_FILES, 'trusttext' => true]);
        $mform->setType('description', PARAM_RAW);

        // Instruction.
        $mform->addElement('editor', 'instruction', $strings->instruction, '', ['maxfiles' => EDITOR_UNLIMITED_FILES,
        'trusttext' => true]);
        $mform->setType('instruction', PARAM_RAW);

        // Preset Icon.
        $theme = \theme_config::load($PAGE->theme->name);
        $faiconsystem = \core\output\icon_system_fontawesome::instance($theme->get_icon_system());
        $iconlist = $faiconsystem->get_core_icon_map();
        $mform->addElement('autocomplete', 'icon', $strings->preseticon, $iconlist);
        $mform->setType('icon', PARAM_TEXT);

        // Preset file options.
        $fileoptions = \pulseaddon_preset\instance::preset_fileoptions();

        // Preset template.
        $mform->addElement('filemanager', 'preset_template', $strings->preset_template, null, $fileoptions);
        $mform->addHelpButton('preset_template', 'preset_template', 'pulse');
        $mform->addRule('preset_template', get_string('required'), 'required');

        // List of configrable parameters.
        $configlist = self::get_pulse_config_list();
        $configselect = $mform->addElement('autocomplete', 'configparams', $strings->configrableparams, $configlist);
        $configselect->setMultiple(true);

        $mform->addElement('checkbox', 'status', $strings->presetstatus, $strings->statuslabel, 1);
        $mform->setType('status', PARAM_INT);

        $order = $this->orderlist();
        $action = optional_param('action', null, PARAM_TEXT);
        if ($action == 'create') {
            $range = range(1, count($order) + 1);
            $order = array_reverse(array_combine(array_values($range), $range), true);
        }
        $mform->addElement('select', 'order_no', $strings->presetorder, $order);
        $mform->setType('order_no', PARAM_INT);
        $mform->addRule('order_no', get_string('error'), 'numeric', null, 'client');

        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_TEXT);
        $mform->setDefault('action', 'create');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        // Submit action buttons.
        $this->add_action_buttons();
    }

    /**
     * List of pulse module form fields list with config label.
     *
     * @return array List of available form fields.
     */
    public static function get_pulse_config_list(): array {
        global $PAGE, $CFG, $COURSE;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot.'/mod/pulse/mod_form.php');

        $fields = [];
        $course = get_course(1);
        $course->enablecompletion = true;
        $course->showcompletionconditions = true;
        $COURSE = $course;

        list($module, $context, $cw, $cm, $data) = \prepare_new_moduleinfo_data($course, 'pulse', 0);
        $PAGE->start_collecting_javascript_requirements();
        $pulseform = new \mod_pulse_mod_form($data, 0, $cm, $course);
        $PAGE->end_collecting_javascript_requirements();
        $header = '';

        foreach ($pulseform->_form->_elements as $element) {
            $hide = ['hidden', 'html', 'submit', 'static'];
            if (in_array($element->_type, $hide)) {
                continue;
            }
            if ($element->_type == 'header') {
                $header = $element->_text;
            } else if ($element instanceof \MoodleQuickForm_group) {
                $label = (($element->_label) ? $element->_label : $element->_name);
                if (strpos($element->_name, 'relativedate') !== false) {
                    $label = get_string('schedule:relativedate', 'pulse');
                }
                if (strpos($element->_name, 'fixeddate') !== false) {
                    $label = get_string('schedule:fixeddate', 'pulse');
                }
                $fields[$element->_name] = $header .' > '. $label;
            } else {
                $label = (($element->_label) ? $element->_label : $element->_text);
                $fields[$element->_attributes['name']] = $header.' > '.$label;
            }
        }
        // Remove session key.
        if (!empty($fields)) {
            unset($fields['sesskey']);
            unset($fields['_qf__mod_pulse_mod_form']);
        }
        return $fields;
    }

    /**
     * Prepare the options list for order config to order the preset.
     *
     * @return array List of order numbers.
     */
    public function orderlist(): array {
        global $DB;
        $count = $DB->count_records('pulse_presets');
        if ($count > 1) {
            $range = range(1, $count);
            return array_combine(array_values($range), $range);
        }
        return [1];
    }

    /**
     * List of strings used in form definition.
     *
     * @return object
     */
    public function get_strings(): object {
        return get_strings([
            'title',
            'description',
            'instruction',
            'preseticon',
            'preset_template',
            'presetstatus',
            'statuslabel',
            'presetorder',
            'configrableparams',
        ], 'pulse');
    }

    /**
     * Format the record data to editor data format used in form.
     *
     * @param array $data Pulsepro Record data.
     * @return array Updated editor data.
     */
    public static function formatdata($data) {
        $editorfields = [
            'first_content' => 'first_content_editor',
            'second_content' => 'second_content_editor',
            'recurring_content' => 'recurring_content_editor',
        ];
        foreach ($editorfields as $name => $editor) {
            \mod_pulse\preset::format_editordata($data, $name, $editor);
        }
        return $data;
    }

    /**
     * Update the give pulse pro custom data for restored module which is created using apply and save method.
     *
     * @param int $pulseid ID of created pulse module.
     * @param array $configparams Custom config data given by users.
     * @return void
     */
    public static function update_preset_config_params(int $pulseid, $configparams) {
        global $DB;
        $notifications = ['first', 'second', 'recurring'];
        foreach ($notifications as $reminder) {
            $content = $reminder.'_content_editor';
            if (isset($configparams[$content]) && !empty($configparams[$content]['text'])) {
                $editor = $configparams[$content];
                $configparams[$reminder.'_content'] = file_save_draft_area_files(
                                                        $editor['itemid'],
                                                        $configparams['contextid'],
                                                        'mod_pulse',
                                                        $reminder.'_content', 0,
                                                        ['subdirs' => true], $editor['text']
                                                    );

                $configparams[$reminder.'_contentformat'] = $editor['format'];
            }
        }

        if ($prodata = $DB->get_record('pulseaddon_reminder', ['pulseid' => $pulseid])) {
            $configparams['id'] = $prodata->id;
            $configparams['pulseid'] = $prodata->pulseid;
            $DB->update_record('pulseaddon_reminder', (object) $configparams);
        } else {
            $configparams['pulseid'] = $pulseid;
            $DB->insert_record('pulseaddon_reminder', (object) $configparams);
        }
    }

    /**
     * Removed the un modified reminder data to prevent overwrite with back up data.
     *
     * @param array $configdata
     * @return void
     */
    public static function clean_configdata(&$configdata) {
        $reminders = ['first', 'second', 'recurring'];
        $methods = ['fixed', 'relative'];
        foreach ($reminders as $reminder) {
            $name = $reminder.'_schedule';
            if (!isset($configdata[$name.'_arr_changed']) || empty($configdata[$name.'_arr_changed'])) {
                unset($configdata[$name]);
            }
            foreach ($methods as $method) {
                $name = $reminder.'_'.$method.'date';
                if (!isset($configdata[$name.'_changed']) || empty($configdata[$name.'_changed'])) {
                    unset($configdata[$name]);
                }
            }
        }
    }
}
