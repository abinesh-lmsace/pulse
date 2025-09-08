@mod @mod_pulse @pulse_instance_conditions @_file_upload
Feature: Pulse automation instances conditions
  In order to check the the pulse automation template works
  As a teacher.

  Background:
    Given the following "categories" exist:
      | name  | category | idnumber |
      | Cat 1 | 0        | CAT1     |
      | Cat 2 | 0        | CAT2     |
      | Cat 3 | CAT1     | CAT3     |
    And the following "course" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
      | Course 2 | C2        | CAT1     | 1                |
      | Course 3 | C3        | CAT2     | 1                |
      | Course 4 | C4        | CAT3     | 1                |
    And the following "users" exist:
      | username | firstname | lastname | email             |
      | student1 | student   | User 1   | student1@test.com |
      | student2 | student   | User 2   | student1@test.com |
      | teacher1 | Teacher   | User 1   | teacher1@test.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | name    | course | idnumber | intro            | section | completion |
      | assign   | Assign1 | C1     | assign1  | Page description | 1       | 1          |
      | assign   | Assign2 | C1     | assign2  | Page description | 2       | 1          |
      | forum    | Forum1  | C1     | forum1   | Page description | 1       | 1          |
      | page     | Page1   | C1     | page1    | Page description | 1       | 1          |

    And I am on the "Course 1" "course" page logged in as "admin"
    And I navigate to "Course completion" in current page administration
    And I set the field "overall_aggregation" to "2"
    And I expand all fieldsets
    And I set the field "Assignment - Assign1" to "1"
    And I set the field "Page - Page1" to "1"
    And I set the field "Forum - Forum1" to "1"
    And I set the field "Assignment - Assign2" to "1"
    And I set the field "activity_aggregation" to "2"
    And I press "Save changes"

  @javascript
  Scenario: Assignment Grade to pass with frequency of automation template instance
    Given I log in as "admin"
    Then I create automation template with the following fields to these values:
        | Title           | Activity Completion |
        | Reference       | activity completion |
        | Frequency limit | 2                   |
    And I click on ".action-edit" "css_element" in the "Activity Completion" "table_row"
    And I click on "Condition" "link" in the "#automation-tabs" "css_element"
    And I set the following fields to these values:
        | Trigger operator    | Any |
        | Activity completion | All |
    And I click on "Notification" "link" in the "#automation-tabs" "css_element"
    And I set the following fields to these values:
        | Recipients | Student |
        | Cc         | Teacher |
    And I press "Save changes"
    And I should see "Activity Completion" in the "#pulse_automation_template" "css_element"
    And I am on "Course 1" course homepage
    And I follow "Automation"
    When I open the autocomplete suggestions list
    And I click on "Activity Completion" item in the autocomplete list
    Then I click on "Add automation instance" "button"
    And I set the following fields to these values:
    | Reference | Grade to pass |
    And I click on "Condition" "link" in the "#automation-tabs" "css_element"
    And I click on "#id_override_triggeroperator" "css_element" in the "#id_generalconditionscontainer" "css_element"
    And I set the field "Trigger operator" to "Any"
    And I click on "#id_override_condition_activity_status" "css_element" in the "#fitem_id_condition_activity_status" "css_element"
    And I set the field "Activity completion" to "All"
    And I set the field "Select activities" to "Assign1"
    And I press "Save changes"
    And I am on "Course 1" course homepage
    And I follow "Assign1"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the following fields to these values:
        | assignsubmission_file_enabled | 1     |
        | Grade to pass                 | 80.00 |
        | id_completion_2               | 1     |
        | completionview                | 1     |
        | completionsubmit              | 1     |
        | completionusegrade            | 1     |
        | id_completionpassgrade_1      | 1     |
    And I press "Save and return to course"
    And I log out
    And I am on the "Course 1" course page logged in as student1
    And I am on the "Assign1" "assign activity" page
    And I click on "Add submission" "button"
    And I upload "mod/pulse/tests/fixtures/image.jpg" file to "File submissions" filemanager
    And I press "Save changes"
    And I am on the "Assign1" "assign activity" page
    And I click on "Submit assignment" "button"
    And I should see "Confirm submission" in the ".submitforgrading h3" "css_element"
    And I click on "Continue" "button"
    And I should see "Submission status" in the ".submissionstatustable h3" "css_element"
    And I should see "Submitted for grading" in the "Submission status" "table_row"
    And I log out
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I am on the "Assign1" "assign activity" page
    And I click on "Grade" "link" in the ".tertiary-navigation" "css_element"
    And I should see "Submitted for grading" in the ".submissionstatustable .submissionstatussubmitted" "css_element"
    And I should see "Not graded" in the ".submissionstatustable .submissionnotgraded" "css_element"
    And I set the following fields to these values:
        | Grade out of 100 | 60 |
    And I press "Save changes"
    And I should see "Graded" in the ".submissionstatustable .submissiongraded" "css_element"
    And I log out
    And I am on the "Course 1" course page logged in as student1
    And I am on the "Assign1" "assign activity" page
    And I should see "Failed:" in the ".completion-info .badge:last-child strong" "css_element"
    And I should see "Graded" in the ".submissionstatustable .submissiongraded" "css_element"
    And I log out
    And I log in as "admin"
    And I am on "Course 1" course homepage
    And I navigate to "Automation" in current page administration
    Then I click on ".action-report" "css_element" in the "Activity Completion" "table_row"
    And I switch to a second window
    And I should see "Nothing to display" in the ".alert.alert-block" "css_element"
    And I close all opened windows
    And I log out
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I am on the "Assign1" "assign activity" page
    And I click on "Grade" "link" in the ".tertiary-navigation" "css_element"
    And I should see "Submitted for grading" in the ".submissionstatustable .submissionstatussubmitted" "css_element"
    And I should see "Graded" in the ".submissionstatustable .submissiongraded" "css_element"
    And I set the following fields to these values:
        | Grade out of 100 | 95 |
    And I press "Save changes"
    And I should see "Graded" in the ".submissionstatustable .submissiongraded" "css_element"
    And I log out
    And I am on the "Course 1" course page logged in as student1
    And I am on the "Assign1" "assign activity" page
    And I should see "Done:" in the ".completion-info .badge:last-child strong" "css_element"
    And I should see "Graded" in the ".submissionstatustable .submissiongraded" "css_element"
    And I log out
    And I log in as "admin"
    And I am on "Course 1" course homepage
    And I navigate to "Automation" in current page administration
    Then I click on ".action-report" "css_element" in the "Activity Completion" "table_row"
    And I switch to a second window
    And the following should exist in the "reportbuilder-table" table:
        | Full name      | Message type        | Course full name | Status |
        | student User 1 | Activity Completion | Course 1         | sent   |
        | student User 1 | Activity Completion | Course 1         | sent   |
    Then ".reportbuilder-table tbody tr:not(.emptyrow):nth-child(3)" "css_element" should not exist

  @javascript
  Scenario: Grade to pass Forum activity automation template instance
    Given I log in as "admin"
    Then I create automation template with the following fields to these values:
        | Title     | Forum Completion |
        | Reference | Forum completion |
    And I click on ".action-edit" "css_element" in the "Forum Completion" "table_row"
    And I click on "Condition" "link" in the "#automation-tabs" "css_element"
    And I set the following fields to these values:
        | Trigger operator    | Any |
        | Activity completion | All |
    And I click on "Notification" "link" in the "#automation-tabs" "css_element"
    And I set the following fields to these values:
        | Recipients | Student |
        | Cc         | Teacher |
    And I press "Save changes"
    And I should see "Forum Completion" in the "#pulse_automation_template" "css_element"
    And I am on "Course 1" course homepage
    And I follow "Automation"
    When I open the autocomplete suggestions list
    And I click on "Forum Completion" item in the autocomplete list
    Then I click on "Add automation instance" "button"
    And I set the following fields to these values:
        | Reference | Grade to pass |
    And I click on "Condition" "link" in the "#automation-tabs" "css_element"
    And I click on "#id_override_triggeroperator" "css_element" in the "#id_generalconditionscontainer" "css_element"
    And I set the field "Trigger operator" to "Any"
    And I click on "#id_override_condition_activity_status" "css_element" in the "#fitem_id_condition_activity_status" "css_element"
    And I set the field "Activity completion" to "All"
    And I set the field "Select activities" to "Forum1"
    And I press "Save changes"
    And I am on "Course 1" course homepage
    And I follow "Forum1"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the following fields to these values:
        | grade_forum[modgrade_type]   | Point       |
        | Grade to pass                | 80.00       |
        | id_completion_2              | 1           |
        | completionview               | 1           |
        | completionpostsenabled       | 1           |
        | completiondiscussionsenabled | 1           |
        | completionrepliesenabled     | 1           |
        | completionusegrade           | 1           |
        | completiongradeitemnumber    | Whole forum |
        | id_completionpassgrade_1     | 1           |
    And I press "Save and display"
    And I log out
    And I am on the "Course 1" course page logged in as student1
    And I am on the "Forum1" "forum activity" page
    And I click on "a.btn-primary" "css_element" in the ".navitem:nth-child(2)" "css_element"
    And I set the following fields to these values:
        | Subject | Forum topic                   |
        | Message | Benefits of group discussions |
    And I press "Post to forum"
    And I should see "Your post was successfully added." in the ".alert.alert-block" "css_element"
    And I log out
    And I log in as "teacher1"
    And I am on the "Forum1" "forum activity" page
    And I click on "Forum topic" "link" in the ".topic" "css_element"
    And I click on "a:last-child" "css_element" in the "article .post-actions" "css_element"
    And I set the following fields to these values:
        | post | Group discussion on forum topic |
    And I click on "Post to forum" "button"
    And I am on the "Forum1" "forum activity" page
    And I click on "Grade users" "button"
    And I should see "Grading (Forum1)" in the ".grader-grading-panel-display h4" "css_element"
    And I set the following fields to these values:
        | grade | 60 |
    And I click on "Save" "button"
    And I log out
    And I am on the "Course 1" course page logged in as student1
    And I am on the "Forum1" "forum activity" page
    And I should see "Failed:" in the ".completion-info .badge:last-child strong" "css_element"
    And I log out
    And I log in as "admin"
    And I am on "Course 1" course homepage
    And I navigate to "Automation" in current page administration
    Then I click on ".action-report" "css_element" in the "Forum Completion" "table_row"
    And I switch to a second window
    And I should see "Nothing to display" in the ".alert.alert-block" "css_element"
    And I close all opened windows
    And I log out
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I am on the "Forum1" "forum activity" page
    And I click on "Grade users" "button"
    And I should see "Grading (Forum1)" in the ".grader-grading-panel-display h4" "css_element"
    And I set the following fields to these values:
        | grade | 95 |
    And I press "Save changes"
    And I log out
    And I am on the "Course 1" course page logged in as student1
    And I am on the "Forum1" "forum activity" page
    And I should see "Done:" in the ".completion-info .badge:last-child strong" "css_element"
    And I log out
    And I log in as "admin"
    And I am on "Course 1" course homepage
    And I navigate to "Automation" in current page administration
    Then I click on ".action-report" "css_element" in the "Forum Completion" "table_row"
    And I switch to a second window
    And I should see "Nothing to display" in the ".alert.alert-block" "css_element"
    And I close all opened windows
    And I log out
    And I am on the "Course 1" course page logged in as student1
    And I am on the "Forum1" "forum activity" page
    And I click on "Forum topic" "link" in the ".topic" "css_element"
    And I click on "a:last-child" "css_element" in the "article .post-actions" "css_element"
    And I set the following fields to these values:
        | post | Group discussion on forum topic |
    And I click on "Post to forum" "button"
    And I am on the "Forum1" "forum activity" page
    And I log out
    And I log in as "admin"
    And I am on "Course 1" course homepage
    And I navigate to "Automation" in current page administration
    Then I click on ".action-report" "css_element" in the "Forum Completion" "table_row"
    And I switch to a second window
    And the following should exist in the "reportbuilder-table" table:
        | Full name      | Message type        | Course full name | Status |
        | student User 1 | Forum Completion    | Course 1         | sent   |

  @javascript
  Scenario: Course summary plain placeholder
    Given I log in as "admin"
    Then I create automation template with the following fields to these values:
        | Title     | Template1 |
        | Reference | temp1     |
    And I should see "Template1" in the "temp1" "table_row"
    And I click on ".action-edit" "css_element" in the "Template1" "table_row"
    And I click on "Notification" "link" in the "#automation-tabs" "css_element"
    And I click on "#id_pulsenotification_headercontent_editor_ifr" "css_element" in the "#fitem_id_pulsenotification_headercontent_editor" "css_element"
    And I wait "2" seconds
    And I click on ".fa-angle-double-down" "css_element" in the "#header-email-vars-button" "css_element"
    And I click on "Show more" "link" in the ".User_field-placeholders" "css_element"

    And I click on pulse "id_pulsenotification_headercontent_editor" editor
    And I click on "Summaryplain" "link" in the ".Course_field-placeholders .placeholders" "css_element"
    And I click on "Preview" "button" in the "#fitem_id_pulsenotification_preview" "css_element"
    And I should see "Acceptance test site" in the "Preview" "dialogue"
    And I click on "button" "css_element" in the "Preview" "dialogue"
    And I press "Save changes"
    And I log out

  @javascript
  Scenario: Feedback placeholder & Month delay
    Given I log in as "admin"
    Then I create automation template with the following fields to these values:
        | Title     | Activity Completion |
        | Reference | activity completion |
    And I click on ".action-edit" "css_element" in the "Activity Completion" "table_row"
    And I click on "Condition" "link" in the "#automation-tabs" "css_element"
    And I set the following fields to these values:
        | Trigger operator    | Any |
        | Activity completion | All |
    And I click on "Notification" "link" in the "#automation-tabs" "css_element"
    And I wait "10" seconds
    And I set the following fields to these values:
        | id_pulsenotification_notifydelay              | After   |
        | id_pulsenotification_delayduration_timeunit   | months  |
        | pulsenotification_delayduration[number]       | 1       |
        | Recipients                                    | Student |
        | Cc                                            | Teacher |
        | Subject                                       | Assignment Feedback placeholder |
    And I press "Save changes"
    And I should see "Activity Completion" in the "#pulse_automation_template" "css_element"
    And I am on "Course 1" course homepage
    And I follow "Automation"
    When I open the autocomplete suggestions list
    And I click on "Activity Completion" item in the autocomplete list
    Then I click on "Add automation instance" "button"
    And I set the following fields to these values:
        | Reference | Grade to pass |
    And I click on "Condition" "link" in the "#automation-tabs" "css_element"
    And I click on "#id_override_triggeroperator" "css_element" in the "#id_generalconditionscontainer" "css_element"
    And I set the field "Trigger operator" to "Any"
    And I click on "#id_override_condition_activity_status" "css_element" in the "#fitem_id_condition_activity_status" "css_element"
    And I set the field "Activity completion" to "All"
    And I set the field "Select activities" to "Assign1"
    And I press "Save changes"
    And I am on "Course 1" course homepage
    And I follow "Assign1"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the following fields to these values:
        | assignfeedback_comments_enabled | 1     |
        | assignsubmission_file_enabled   | 1     |
        | Grade to pass                   | 80.00 |
        | id_completion_2                 | 1     |
        | completionview                  | 1     |
        | completionusegrade              | 1     |
        | id_completionpassgrade_1        | 1     |
    And I press "Save and return to course"
    And I log out
    And I am on the "Course 1" course page logged in as student1
    And I am on the "Assign1" "assign activity" page
    And I click on "Add submission" "button"
    And I upload "mod/pulse/tests/fixtures/image.jpg" file to "File submissions" filemanager
    And I press "Save changes"
    And I am on the "Assign1" "assign activity" page
    And I click on "Submit assignment" "button"
    And I should see "Confirm submission" in the ".submitforgrading h3" "css_element"
    And I click on "Continue" "button"
    And I should see "Submission status" in the ".submissionstatustable h3" "css_element"
    And I should see "Submitted for grading" in the "Submission status" "table_row"
    And I log out

    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I am on the "Assign1" "assign activity" page
    And I click on "Grade" "link" in the ".tertiary-navigation" "css_element"
    And I should see "Submitted for grading" in the ".submissionstatustable .submissionstatussubmitted" "css_element"
    And I set the following fields to these values:
        | Grade out of 100                 | 95         |
    And I click on ".tox-edit-area" "css_element" in the "#fitem_id_assignfeedbackcomments_editor" "css_element"
    And I set the following fields to these values:
        | id_assignfeedbackcomments_editor | Good work! |
    And I press "Save changes"
    And I should see "Graded" in the ".submissionstatustable .submissiongraded" "css_element"
    And I log out

    And I log in as "admin"
    And I am on "Course 1" course homepage
    And I follow "Automation"
    Then I click on ".action-report" "css_element" in the "Activity Completion" "table_row"
    And I switch to a second window
    And the following should exist in the "reportbuilder-table" table:
        | Course full name | Message type        | Subject                         | Full name      | Time created                    | Status |
        | Course 1         | Activity Completion | Assignment Feedback placeholder | student User 1 | ##now##%A, %d %B %Y, %I:%M %p## | Queued |
    And I close all opened windows
    And I log out

    And I log in as "admin"
    And I am on "Course 1" course homepage
    And I follow "Automation"
    And I click on ".action-edit" "css_element" in the "Activity Completion" "table_row"
    And I click on "Notification" "link" in the "#automation-tabs" "css_element"
    And I click on "#id_pulsenotification_headercontent_editor_ifr" "css_element" in the "#fitem_id_pulsenotification_headercontent_editor" "css_element"
    And I click on ".fa-angle-double-down" "css_element" in the "#header-email-vars-button" "css_element"
    And I click on "Show more" "link" in the ".User_field-placeholders" "css_element"

    And I click on pulse "id_pulsenotification_headercontent_editor" editor
    And I click on "Feedback" "link" in the ".Assignment_field-placeholders .placeholders" "css_element"
    And I set the following fields to these values:
        | Dynamic content | Assign1 |
    And I click on "Preview" "button" in the "#fitem_id_pulsenotification_preview" "css_element"
    And I should see "Good work!" in the "Preview" "dialogue"
    And I click on "button" "css_element" in the "Preview" "dialogue"
    And I press "Save changes"
    And I log out

  @javascript
  Scenario: Pulse User Approval option
    Given I am on "Course 1" course homepage with editing mode on
    And I click on "Add an activity or resource" "button"
    And I click on "Pulse" "link"
    And I set the following fields to these values:
        | Title                       | Approval status |
        | Content                     | Approval status |
    And I expand all fieldsets
    And I set the activity completion tracking
    And I click on "Require approval by one of the following roles" "checkbox"
    And I click on ".form-autocomplete-downarrow" "css_element" in the "#fgroup_id_completionrequireapproval" "css_element"
    And I click on "Teacher" "list_item" in the "#fgroup_id_completionrequireapproval [class='form-autocomplete-suggestions']" "css_element"
    And I press "Save and return to course"
    And I log out
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I click on "Approve users" "link" in the ".approve-user-wrapper" "css_element"
    And I should see "Unset" in the "student User 1" "table_row"
    And I should see "Unset" in the "student User 2" "table_row"
    And I should see "student User 1" in the "participants" "table"
    When I click on "Approve" "link" in the "student User 1" "table_row"
    And I should see "Approval successful" in the ".notifications" "css_element"
    And I should see "Approved" in the "student User 1" "table_row"
    And I click on "Decline" "link" in the "student User 2" "table_row"
    And I should see "Approval denied" in the ".notifications" "css_element"
    And I should see "Declined" in the "student User 2" "table_row"
