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
 * Reaction addon instance class to work with pulse.
 *
 * @package    pulseaddon_reaction
 * @copyright  2024 bdecent GmbH <https://bdecent.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_reaction;

use moodle_exception;
use moodle_url;
use stdClass;

/**
 * This class represents an instance with properties and methods to manage its reactions.
 */
class instance extends \mod_pulse\addon\base {

    /**
     * Mark complete reaction type constant value
     */
    public const REACTION_MARKCOMPLETE = 1;

    /**
     * Rate reaction type value.
     */
    public const REACTION_RATE = 2;

    /**
     * Value of approval by selected role reaction method.
     */
    public const REACTION_APPROVAL = 3;

    /**
     * Reaction display only in notification.
     */
    public const REACTION_DISPLAY_NOTIFICATION = 0;

    /**
     * Reaction display type both location.
     */
    public const REACTION_DISPLAY_BOTH = 1;

    /**
     * Reaction display type content location.
     */
    public const REACTION_DISPLAY_CONTENT = 2;

    /**
     * List of strings for different reaction methods.
     *
     * @return array
     */
    public static function reactions(): array {

        return [
            0 => get_string('noreaction', 'mod_pulse'),
            1 => get_string('markcomplete', 'mod_pulse'),
            2 => get_string('rate', 'mod_pulse'),
            3 => get_string('approve', 'mod_pulse'),
        ];
    }

    /**
     * Get the name of the addon.
     *
     * @return string Name of the addon
     */
    public function get_name() {
        return 'reaction';
    }

    /**
     * Update the status of the existing user reactions, if the reaction type is changed.
     *
     * @param stdClass $pulse The pulse record object
     * @return void
     */
    public function instance_update($pulse) {
        global $DB;

        $prodata = $DB->get_record('pulse_options', ['pulseid' => $pulse->id, 'name' => 'reactiontype']);

        if (!empty($prodata) && $prodata->value != $pulse->options['reactiontype']) {
            $DB->set_field('pulseaddon_reaction_tokens', 'status', '0', ['pulseid' => $pulse->id]);
        }
    }

    /**
     * Delete the instance related data from the reaction tables.
     *
     * @return void
     */
    public function instance_delete() {
        global $DB;

        // Remove pulse tokens completion records.
        if ($DB->record_exists('pulseaddon_reaction_tokens', ['pulseid' => $this->pulseid])) {
            $DB->delete_records('pulseaddon_reaction_tokens', ['pulseid' => $this->pulseid]);
        }
    }

    /**
     * Include the reaction contents to the pulse content to display in course page.
     *
     * @param stdClass $instance The pulse instance object
     * @return string The reaction content
     */
    public function get_cm_infocontent($instance) {
        return $this->reaction_content($instance, 'infocontent');
    }

    /**
     * Generates the reaction content based on the instance and type.
     *
     * @param object $instance The instance object containing pulse and user information.
     * @param string $type The type of reaction content to generate, default is 'notification'.
     * @return string The generated reaction content or an empty string if conditions are not met.
     */
    protected function reaction_content($instance, $type='notification') {

        global $DB, $OUTPUT;

        if (!isset($instance->pulse) || empty($instance->pulse) || !isset($instance->pulse->id)) {
            return '';
        }

        if (empty($instance->pulse->options->reactiontype) || $instance->pulse->options->reactiontype == 0) {
            return '';
        }

        $pulseid = $instance->pulse->id;
        $reactiontype = $instance->pulse->options->reactiontype;
        $userid = $instance->user->id;

        $approveuser = isset($instance->user->approveuser) ? $instance->user->approveuser : $userid;
        $relateduserid = null;
        $cm = get_coursemodule_from_instance('pulse', $pulseid);

        if ((($type == 'content' || $type == 'infocontent') && $instance->pulse->options->reactiondisplay == 0)
            || ($type == 'content' && $reactiontype == self::REACTION_RATE)
            || ($type == 'notification' && $instance->pulse->options->reactiondisplay == self::REACTION_DISPLAY_CONTENT)
            || ($type == 'content' && $reactiontype == self::REACTION_APPROVAL)) {
            return '';
        }

        // Check the reaction display type is content only or both.
        // If generate token called from content side.
        // if already token generated just reuse the tokens.
        $params = ['pulseid' => $pulseid, 'userid' => $userid, 'reactiontype' => $reactiontype];
        if ($instance->pulse->options->reactiontype == self::REACTION_APPROVAL) {
            // Each user has received their own notification with approval token.
            $params['userid'] = $approveuser;
            $params['relateduserid'] = $userid;
            $relateduserid = $userid;
            $userid = $approveuser;
        }

        $token = $this->get_token($params);
        // Token not found create a new one.
        if (!isset($token) || $token == '') {
            $token = $this->generate_token($pulseid, $userid, $reactiontype, $relateduserid);
        }

        $reactionurl = new moodle_url('/mod/pulse/addons/reaction/reaction.php', ['token' => $token]);
        $reactionurl = $reactionurl->out();
        switch ($instance->pulse->options->reactiontype) {
            case 0: // No reaction.
                $content = '';
                break;
            case 1: // Mark Complete.
                $content = get_string('reaction:markcomplete', 'mod_pulse', ['reactionurl' => $reactionurl]);
                break;
            case 2: // Rate.
                $data['reactionurl_like'] = new moodle_url('/mod/pulse/addons/reaction/reaction.php', [
                    'token' => $token, 'rate' => 2]);
                $data['reactionurl_dislike'] = new moodle_url('/mod/pulse/addons/reaction/reaction.php', [
                    'token' => $token, 'rate' => 1]);
                $content = $OUTPUT->render_from_template('pulseaddon_reaction/ratereaction', $data);
                break;
            case 3: // Approve.
                if (\mod_pulse\helper::pulse_has_approvalrole(
                    $instance->pulse->completionapprovalroles, $cm->id, true, $approveuser)) {

                    $content = get_string('reaction:approve', 'mod_pulse', ['reactionurl' => $reactionurl]);
                } else {
                    $content = '';
                }
                break;
        }

        return $content;
    }

    /**
     * Get previously generated token if token doesn't expired.
     *
     * @param array $params Array of parameters to search the token.
     * @return string Return token if found else empty string.
     */
    protected function get_token(array $params) {
        global $DB;
        $token = '';
        $record  = $DB->get_record('pulseaddon_reaction_tokens', $params);
        if ($record) {
            $token = $record->token;
            $timeexpire = get_config('pulseaddon_reaction', 'expiretime'); // Time expiration in seconds.
            if ($timeexpire != 0 && (time() - $record->timecreated) >= $timeexpire) {
                $token = '';
            }
        }
        return $token;
    }

    /**
     * Generate token for the reaction.
     * Tokend generated by moodle default token generation method. hashed using md5.
     *
     * @param  int $pulseid
     * @param  int $userid
     * @param  int $reactiontype Selected reaction type.
     * @param  int $relateduserid If reation for approval than the student id is related userid.
     * @return string Return generated token.
     */
    protected function generate_token($pulseid, $userid, $reactiontype, $relateduserid=null) {
        global $DB, $USER;
        // Make sure the token doesn't exist (even if it should be almost impossible with the random generation).
        $numtries = 0;
        do {
            $numtries ++;
            $generatedtoken = md5( uniqid(rand(), 1) );
            if ($numtries > 5) {
                throw new moodle_exception('tokengenerationfailed');
            }
        } while ($DB->record_exists('pulseaddon_reaction_tokens', ['token' => $generatedtoken]));
        $newtoken = new stdClass();
        $newtoken->token = $generatedtoken;
        $newtoken->pulseid = $pulseid;
        $newtoken->userid = $userid;
        $newtoken->relateduserid = $relateduserid;
        $newtoken->reactiontype = $reactiontype;
        $newtoken->status = 0;
        $newtoken->timemodified = time();
        $newtoken->timecreated = time();
        $params = ['pulseid' => $pulseid, 'userid' => $userid, 'reactiontype' => $reactiontype];
        if ($reactiontype == self::REACTION_APPROVAL) {
            $params['relateduserid'] = $relateduserid;
        }
        if ($record = $DB->get_record('pulseaddon_reaction_tokens', $params) ) {
            $newtoken->id = $record->id;
            $DB->update_record('pulseaddon_reaction_tokens', $newtoken);
            return $generatedtoken;
        } else {
            if ($DB->insert_record('pulseaddon_reaction_tokens', $newtoken)) {
                return $generatedtoken;
            }
        }
        return '';
    }

    /**
     * Adds custom form fields before the invitation section in the form.
     *
     * @param MoodleQuickForm $mform The form being built.
     * @param stdClass $instance The instance of the module.
     */
    public static function form_fields_before_invitation(&$mform, $instance) {
        global $PAGE;

        // Implementation for extending the form
        // Reaction section header.
        $mform->addElement('header', 'reactions', get_string('reactions', 'mod_pulse'));

        $mform->addElement('select', 'options[reactiontype]', get_string('reactiontype', 'pulse'), self::reactions());
        $mform->setType('options[reactiontype]', PARAM_INT);
        $mform->addHelpButton('options[reactiontype]', 'reactiontype', 'mod_pulse');

        $displaytype = [
            0 => get_string('displaytype:notificationonly', 'mod_pulse'),
            1 => get_string('displaytype:notificationcontent', 'mod_pulse'),
            2 => get_string('displaytype:contentonly', 'mod_pulse'),
        ];
        $mform->addElement('select', 'options[reactiondisplay]', get_string('reactiondisplaytype', 'mod_pulse'), $displaytype);
        $mform->setType('options[reactiondisplay]', PARAM_INT);
        $mform->addHelpButton('options[reactiondisplay]', 'reactiondisplaytype', 'mod_pulse');

        $PAGE->requires->js_call_amd('pulseaddon_reaction/reaction', 'init');
    }

    /**
     * Placeholder for the reaction content. This is used to replace the placeholders in the email templates.
     *
     * @return array The list of placeholders.
     */
    public static function get_email_placeholders() {
        return ['Reaction' => ['reaction']];
    }

    /**
     * Get the reaction content for the email vars.
     *
     * @param stdClass $instance
     * @return void
     */
    public static function get_emailvars_definition_reaction($instance) {
        return (new self($instance->pulse->id))->reaction_content($instance, $instance->type ?? 'notification');
    }

    /**
     * Report add columns.
     *
     * @param array $headers
     * @param array $columns
     * @return void
     */
    public static function report_add_columns(&$headers, &$columns) {
        $columns[] = 'reaction';
        $headers[] = get_string('reactions', 'mod_pulse');
    }

    /**
     * User unenrolled event observer.
     *
     * Remove the unenrolled user records related to list of pulse instances created in the course.
     * It deletes the users availability data, reaction tokens and activity completion data.
     *
     * @param  stdclass $event
     * @return bool true
     */
    public static function event_user_enrolment_deleted($event) {
        global $DB;
        $userid = $event->relateduserid; // Unenrolled user id.
        $courseid = $event->courseid;
        // Retrive list of pulse instance added in course.
        $list = \mod_pulse\helper::course_instancelist($courseid);

        if (!empty($list)) {

            $pulselist = array_column($list, 'instance');
            list($insql, $inparams) = $DB->get_in_or_equal($pulselist);
            $inparams[] = $userid;
            $select = " pulseid $insql AND userid = ? ";

            $select = " pulseid $insql AND (userid = ? OR relateduserid = ? ) ";
            $inparams[] = $userid;
            $DB->delete_records_select('pulseaddon_reaction_tokens', $select, $inparams);
        }

        return true;
    }

    /**
     * course module deleted event observer.
     * Remove the user and instance records for the deleted modules from pulsepro tables.
     *
     * @param  stdclass $event
     * @return void
     */
    public static function event_course_module_deleted($event) {
        global $DB;

        if ($event->other['modulename'] == 'pulse') {

            $pulseid = $event->other['instanceid'];

            // Remove pulse user credits records.
            if ($DB->record_exists('pulseaddon_reaction_tokens', ['pulseid' => $pulseid])) {
                $DB->delete_records('pulseaddon_reaction_tokens', ['pulseid' => $pulseid]);
            }
        }
    }

}
