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
 * Automation instance form for the pulse 2.0.
 *
 * @package   mod_pulse
 * @copyright 2023, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pulse\forms;

defined('MOODLE_INTERNAL') || die('No direct access!');

require_once($CFG->dirroot.'/lib/formslib.php');

use html_writer;
use mod_pulse\automation\templates;
use mod_pulse\automation\helper;
/**
 * Define the automation instance form.
 */
class automation_instance_form extends automation_template_form {

    /**
     * Define the form elements inside the definition function.
     *
     * @return void
     */
    public function definition() {
        global $PAGE, $OUTPUT;

        $mform = $this->_form; // Get the form instance.

        // Set the id of template.
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->updateAttributes(['id' => 'pulse-automation-template' ]);
        $tabs = [
            ['name' => 'autotemplate-general', 'title' => get_string('tabgeneral', 'pulse'), 'active' => 'active'],
            ['name' => 'pulse-condition-tab', 'title' => get_string('tabcondition', 'pulse')],
        ];

        $context = \context_system::instance();

        // Load all actions forms.
        // Define the lang key "formtab" in the action component it automatically includes it.
        foreach (helper::get_actions() as $key => $action) {
            $tabs[] = ['name' => 'pulse-action-'.$key, 'title' => get_string('formtab', 'pulseaction_'.$key)];
        }

        // Instance management tab.
        if ($templateid = $this->get_customdata('id') && has_capability('mod/pulse:manageinstance', $context)) {
            $tabs[] = ['name' => 'manage-instance-tab', 'title' => get_string('tabmanageinstance', 'pulse')];
        }

        $tab = $OUTPUT->render_from_template('mod_pulse/automation_tabs', ['tabs' => $tabs]);
        $mform->addElement('html', $tab);

        // Pulse templates tab content.
        $mform->addElement('html', '<div class="tab-content" id="pulsetemplates-tab-content">');

        // Template options.
        $this->load_template_options($mform);

        // Template conditions.
        $this->load_template_conditions($mform);

        // Load template actions.
        $this->load_template_actions($mform);

        if ($templateid = $this->get_customdata('id')) {
            list($overcount, $overinstances) = templates::create($templateid)->get_instances();

            foreach ($mform->_elements as $key => $element) {

                $elementname = $element->getName();
                if (!isset($overcount[$elementname])) {
                    continue;
                }
                $count = html_writer::tag('span', $overcount[$elementname].' '.get_string('overrides', 'pulse'), [
                    'data-target' => "overridemodal",
                    'data-templateid' => $templateid,
                    'data-element' => $elementname,
                    'class' => 'override-count-element badge mt-1',
                    'id' => "override_$elementname",
                ]);
                $overrideelement = $mform->createElement('html', $count);
                $mform->insertElementBefore($overrideelement, $elementname);

                $mform->addElement('hidden', "overinstance_$elementname", json_encode($overinstances[$elementname]));
                $mform->setType("overinstance_$elementname", PARAM_RAW);
            }
        }

        $mform->addElement('html', html_writer::end_div());

        // Show the list of overriden content.
        $PAGE->requires->js_call_amd('mod_pulse/automation', 'init');

        // Add standard form buttons.
        $this->add_action_buttons(true);
    }

    /**
     * After the instance form elements are defined, create its override options for all elemennts, include hidden instance data.
     * Remove elements doesn't used in instances.
     *
     * @return void
     */
    public function after_definition() {
        global $PAGE, $CFG;

        $mform =& $this->_form;

        $mform->updateAttributes(['id' => 'pulse-automation-template' ]);
        // Courseid.
        $course = $this->_customdata['courseid'] ?? '';
        $mform->addElement('hidden', 'courseid', $course);
        $mform->setType('courseid', PARAM_INT);

        $templateid = $this->_customdata['templateid'] ?? '';
        $mform->addElement('hidden', 'templateid', $templateid);
        $mform->setType('templateid', PARAM_INT);

        $templateid = $this->_customdata['instanceid'] ?? '';
        $mform->addElement('hidden', 'instanceid', $templateid);
        $mform->setType('instanceid', PARAM_INT);

        $mform->removeElement('visible');
        $mform->removeElement('categories');

        // Get the list of elments add in this form. create override button for all elements expect the hidden elements.
        $elements = $mform->_elements;

        // Add the Reference element.
        $reference = $mform->createElement('text', 'insreference', get_string('reference', 'pulse'), ['size' => '50']);
        $mform->insertElementBefore($reference, 'reference');
        $mform->setType('insreference', PARAM_ALPHANUMEXT);
        $mform->addRule('insreference', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('insreference', 'reference', 'pulse');

        $mform->removeElement('reference');

        $templatereference = $this->get_customdata('templatereference');
        $input = html_writer::empty_tag('input',
            ['class' => 'form-control',
            'type' => 'text',
            'value' => $templatereference,
            'disabled' => 'disabled',
            ]);
        $referenceprefix = $mform->createElement('html', html_writer::div($input, 'hide', ['id' => 'pulse-template-reference']));
        $mform->insertElementBefore($referenceprefix, 'insreference');

        $this->load_default_override_elements(['insreference']);

        // Add element to disable instance override options.
        $hasinstanceeditcapability = has_capability('mod/pulse:overridetemplateinstance', \context_course::instance($course));
        $mform->addElement('hidden', 'hasinstanceeditcapability', $hasinstanceeditcapability);
        $mform->setType('hasinstanceeditcapability', PARAM_INT);

        if (!empty($elements)) {
            // List of element type don't need to add the override option.
            $dontoverride = ['html', 'header', 'hidden', 'button'];

            foreach ($elements as $element) {

                if (!in_array($element->getType(), $dontoverride) && $element->getName() !== 'buttonar') {

                    if ($hasinstanceeditcapability) {
                        // Has capability add override element.
                        $this->add_override_element($element, $course);
                    } else {
                        $elementname = $element->getName();
                        $orgelementname = $elementname;
                        $mform->disabledIf($orgelementname, 'hasinstanceeditcapability', 'neq', 1);
                    }
                }
            }
        }
        // Email placeholders.
        $PAGE->requires->js_call_amd('mod_pulse/vars', 'init');
        $PAGE->requires->js_call_amd('mod_pulse/module', 'init', [$CFG->branch]);
    }

    /**
     * Add an override element to the form.
     *
     * @param mixed $element The form element.
     * @param int $course The course ID
     */
    protected function add_override_element($element, $course) {

        $mform =& $this->_form;
        $elementname = $element->getName();
        $orgelementname = $elementname;

        if (stripos($elementname, "[") !== false) {
            $name = str_replace("]", "", str_replace("[", "_", $elementname));
            $name = 'override[' . $name .']';
        } else {
            $name = 'override[' . $elementname .']';
        }

        // Override element already exists, no need to create new one.
        if (isset($mform->_elementIndex[$name])) {
            return;
        }

        $overrideelement = $mform->createElement('advcheckbox', $name, '', '',
        ['group' => 'automation', 'class' => 'custom-control-input'], [0, 1]);

        // Insert the override checkbox before the element.
        if (isset($mform->_elementIndex[$orgelementname]) && $mform->_elementIndex[$orgelementname]
            && has_capability('mod/pulse:overridetemplateinstance', \context_course::instance($course))) {
            $mform->insertElementBefore($overrideelement, $orgelementname);
        }

        // Disable the form fields by default, only enable whens its enabled for overriddden.
        $mform->disabledIf($orgelementname, $name, 'notchecked');
    }

    /**
     * Includ the pulse conditions element to the instance form.
     *
     * @return void
     */
    protected function load_template_conditions() {

        $mform =& $this->_form;
        $mform->addElement('html', '<div class="tab-pane fade" id="pulse-condition-tab"> ');
        $mform->addElement('header', 'generalconditions', '<h3>'.get_string('general').'</h3>');
        // Operator element.
        $operators = [
            \mod_pulse\automation\action_base::OPERATOR_ALL => get_string('all', 'pulse'),
            \mod_pulse\automation\action_base::OPERATOR_ANY => get_string('any', 'pulse'),
        ];
        $mform->addElement('select', 'triggeroperator', get_string('triggeroperator', 'pulse'), $operators);
        $mform->addHelpButton('triggeroperator', 'triggeroperator', 'pulse');

        $conditionplugins = new \mod_pulse\plugininfo\pulsecondition();
        $plugins = $conditionplugins->get_plugins_base();

        foreach ($plugins as $name => $plugin) {
            $mform->addElement('header', $name, get_string('pluginname', 'pulsecondition_'.$name));

            $plugin->load_instance_form($mform, $this);
            $plugin->upcoming_element($mform);
            $mform->setExpanded($name);
        }
        $mform->addElement('html', '</fieldset>'); // E.o of actions triggere tab.
        $mform->addElement('html', html_writer::end_div()); // E.o of actions triggere tab.
    }

    /**
     * Load instance form elments for pulse action plugins.
     *
     * @return void
     */
    protected function load_template_actions() {
        $mform =& $this->_form;
        $actionplugins = new \mod_pulse\plugininfo\pulseaction();
        $plugins = $actionplugins->get_plugins_base();
        foreach ($plugins as $name => $plugin) {
            // Define the form elements inside the definition function.
            $mform->addElement('html', '<div class="tab-pane fcontainer fade" id="pulse-action-'.$name.'"> ');
            $mform->addElement('html', '<h4>'.get_string('pluginname', 'pulseaction_'.$name).'</h4>');

            // Load the instance elements for this action.
            $plugin->load_instance_form($mform, $this);
            $elements = $plugin->default_override_elements();
            $this->load_default_override_elements($elements);
            $mform->addElement('html', html_writer::end_div()); // E.o of actions triggere tab.
        }
    }

    /**
     * Load the default override elements for instances.
     *
     * @param array $elements Config names list to create override by default.
     *
     * @return void
     */
    protected function load_default_override_elements($elements) {

        if (empty($elements)) {
            return false;
        }
        $mform =& $this->_form;
        foreach ($elements as $element) {
            $overridename = "override[$element]";
            $mform->addElement('hidden', $overridename, 1);
            $mform->setType($overridename, PARAM_BOOL);
        }
    }

    /**
     * Get the default values for a specific key from the form.
     *
     * @param string $key The key for the default values.
     * @return array The default values for the specified key.
     */
    public function get_default_values($key) {
        return $this->_form->_defaultValues[$key] ?? [];
    }

    /**
     * Perform actions on the form after data has been defined.
     */
    public function definition_after_data() {
        $plugins = \mod_pulse\plugininfo\pulseaction::instance()->get_plugins_base();
        foreach ($plugins as $name => $plugin) {
            $plugin->definition_after_data($this->_form, $this);
        }
    }
}
