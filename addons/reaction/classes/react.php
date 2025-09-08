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
 * Process user reactions.
 *
 * @package   pulseaddon_reaction
 * @copyright 2021, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pulseaddon_reaction;

use mod_pulse\options;
use stdclass;

/**
 * Class contains the process of user reactions.
 */
class react {

    /**
     * Course module instance.
     *
     * @var mixed
     */
    public $cm;

    /**
     * Current pulse instance record data.
     *
     * @var stdclass
     */
    public $pulse;

    /**
     * Pulse completion data for current pulse instance.
     *
     * @var stdclass
     */
    public $completion;

    /**
     * Pulse instance data.
     *
     * @var object
     */
    public $pulsedata;

    /**
     * Record of the token used to react.
     *
     * @var object
     */
    public $tokenrecord;

    /**
     * @var stdClass $course The course being searched.
     */
    public $course;

    /**
     * @var stdClass $userid ID of token user.
     */
    public $userid;

    /**
     * Fetch pulse, reaction instance and course module instance data.
     *
     * @param  string $token Token used to reaction.
     * @return void
     */
    public function __construct($token) {
        global $DB;

        if ($DB->record_exists('pulseaddon_reaction_tokens', ['token' => $token])) {
            $this->tokenrecord = $DB->get_record('pulseaddon_reaction_tokens', ['token' => $token]);
        } else {
            throw new \moodle_exception('invalidtoken', 'error');
        }
        $this->userid = $this->tokenrecord->userid;
        $pulseid = $this->tokenrecord->pulseid;

        $this->pulse = $DB->get_record('pulse', ['id' => $pulseid]);
        $this->pulsedata = (object) options::init($this->pulse->id)->get_options();

        $this->course = get_course($this->pulse->course);
        $this->cm = get_coursemodule_from_instance('pulse', $pulseid);
        $this->completion = new \completion_info($this->course);
    }

    /**
     * Update the token status when the reactions are used from the email.
     * Then need to update the status to prevent reuse the same.
     * when the reaction type=2 (Rate method) then the status - 2 for like and status - 1 for dislike
     *
     * @param  int $status
     * @return void
     */
    public function update_status($status=1) {
        global $DB;
        $tokenrecord = new stdclass();
        $tokenrecord->id = $this->tokenrecord->id;
        $tokenrecord->status = $status;
        $tokenrecord->timemodified = time();
        $DB->update_record('pulseaddon_reaction_tokens', $tokenrecord);
    }

    /**
     * Updates the mark complete completion for user who reacts by reaction.
     * It updates the completion record and completion state.
     *
     * @return void
     */
    public function mark_complete() {
        global $DB;

        if ($record = $DB->get_record('pulse_completion', ['userid' => $this->userid, 'pulseid' => $this->pulse->id])) {
            $record->selfcompletion = 1;
            $record->selfcompletiontime = time();
            $record->timemodified = time();
            $result = $DB->update_record('pulse_completion', $record);
        } else {
            $record = new stdclass();
            $record->userid = $this->userid;
            $record->pulseid = $this->pulse->id;
            $record->selfcompletion = 1;
            $record->selfcompletiontime = time();
            $record->timemodified = time();
            $result = $DB->insert_record('pulse_completion', $record);
        }

        if ($result) {
            if ($this->completion->is_enabled($this->cm) && $this->pulse->completionself) {
                $this->completion->update_state($this->cm, COMPLETION_COMPLETE, $this->userid);
            }
        }

        $this->update_status();
    }

    /**
     * Reaction process for approve user reaction.
     * Approve reaction will update the approval completion on DB and updates the completion state.
     * Related user id in db is the id of student user who got the approval from email.
     *
     * @return void
     */
    public function approve_user() {
        global $DB, $PAGE;

        $condition = ['userid' => $this->tokenrecord->relateduserid, 'pulseid' => $this->cm->instance];

        if ($record = $DB->get_record('pulse_completion', $condition)) {
            $record->approvalstatus = 1;
            $record->approveduser = $this->userid;
            $record->approvaltime = time();
            $record->timemodified = time();
            $result = $DB->update_record('pulse_completion', $record);
        } else {
            $record = new stdclass();
            $record->userid = $this->tokenrecord->relateduserid;
            $record->pulseid = $this->cm->instance;
            $record->approvalstatus = 1;
            $record->approveduser = $this->userid;
            $record->timemodified = time();
            $record->approvaltime = time();
            $result = $DB->insert_record('pulse_completion', $record);
        }

        if ($result) {
            // Update the pulse module completion state for the current user.
            $completion = new \completion_info($this->course);
            if ($completion->is_enabled($this->cm) && $this->pulse->completionapproval) {
                $completion->update_state($this->cm, COMPLETION_COMPLETE, $this->tokenrecord->relateduserid);
            }
        }
    }

}
