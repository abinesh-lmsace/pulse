@mod @mod_pulse @pulseaddon @pulseaddon_credits
Feature: Credits using pulse module.
  In order to check the the pulse credits
  As a teacher
  I should create and match user custom profile field
  Enrolled user should recevie the pulse credits in theri profile field.

  Background: Create pulse instance.
    Given the following "custom profile fields" exist:
      | datatype | shortname | name    | visible |
      | text     | credits   | Credits | 0       |
    And the following "course" exist:
      | fullname | shortname | category |
      | Test     | C1        | 0        |
      | Course B | C2        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email |
      | student1 | student | User 1 | student1@test.com |
      | student2 | student | user 2 | student2@test.com |
      | teacher1 | Teacher | User 1 | teacher1@test.com |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
    And the following "activity" exists:
      | activity | pulse                |
      | course   | C1                   |
      | idnumber | 00001                |
      | name     | pulse box mode       |
      | intro    | pulse box mode       |
      | section  | 1                    |
    And I log in as "admin"
    And I navigate to "Plugins > Activity modules > Pulse > Pulse credits" in site administration
    Then I set the field "Credits user profile field" to "Credits"
    And I press "Save changes"
    And I log out

  @javascript
  Scenario: Update credit for self enrolment user.
    Given I log in as "admin"
    And I am on "Test" course homepage
    And I navigate to course participants
    And I set the field "Participants tertiary navigation" to "Enrolment methods"
    And I click on "Enable" "link" in the "Self enrolment" "table_row"
    And I am on "Test" course homepage
    And I add pulse to course "Test" section "1" with:
      | Title | pulse credit 50 |
      | Content | pulse credit 50 |
      | Notification recipients  | Student   |
      | options[credits_status]     | 1   |
      | options[credits]            | 100 |
    And I should see "pulse credit 50"
    And I log out
    Then I log in as "student1"
    And I am on "Test" course homepage
    And I press "Enrol me"
    And I log out
    When I log in as "admin"
    And I trigger cron
    And I am on homepage
    And I navigate to "Users > Browse list of users" in site administration
    And I should see "student User 1"
    And I open the action menu in "student User 1" "table_row"
    And I choose "Edit" in the open action menu
    And I follow "Expand all"
    And the field "Credits" in the "Testing" "fieldset" matches value "100"
    Then I am on "Course B" course homepage with editing mode on
    And I navigate to course participants
    And I set the field "Participants tertiary navigation" to "Enrolment methods"
    And I click on "Enable" "link" in the "Self enrolment" "table_row"
    Then I am on "Course B" course homepage
    And I add pulse to course "Course B" section "1" with:
      | Title   | pulse credit -50 |
      | Content | pulse credit -50 |
      | Notification recipients  | Student   |
      | options[credits_status]  | 1         |
      | options[credits]         | -50       |
    And I should see "pulse credit -50"
    And I log out
    Then I log in as "student1"
    And I am on "Course B" course homepage
    And I press "Enrol me"
    And I log out
    And I log in as "admin"
    And I am on "Course B" course homepage
    When I navigate to course participants
    And I press "Enrol users"
    When I set the field "Select users" to "student1"
    And I should see "student User 1"
    And I click on "Enrol users" "button" in the "Enrol users" "dialogue"
    Then I should see "Active" in the "student User 1" "table_row"
    And I should see "1 enrolled users"
    And I am on "Test" course homepage
    And I trigger cron
    And I am on "Test" course homepage
    And I navigate to "Users > Browse list of users" in site administration
    And I should see "student User 1"
    And I open the action menu in "student User 1" "table_row"
    And I choose "Edit" in the open action menu
    And I follow "Expand all"
    And I wait "5" seconds
    And the field "Credits" in the "Testing" "fieldset" matches value "50"

  @javascript
  Scenario: Test the subtract credit from new user.
    Given I log in as "admin"
    Then I am on "Course B" course homepage with editing mode on
    And I add pulse to course "Course B" section "1" with:
      | Title | pulse credit -50 |
      | Content | pulse credit -50 |
      | Notification recipients  | Student  |
      | options[credits_status]  | 1        |
      | options[credits]         | -50      |
    And I should see "pulse credit -50"
    When I navigate to course participants
    And I press "Enrol users"
    When I set the field "Select users" to "student2"
    And I should see "student user 2"
    And I click on "Enrol users" "button" in the "Enrol users" "dialogue"
    Then I should see "Active" in the "student user 2" "table_row"
    And I should see "1 enrolled users"
    And I trigger cron
    And I am on "Test" course homepage
    And I navigate to "Users > Browse list of users" in site administration
    And I should see "student user 2"
    And I open the action menu in "student user 2" "table_row"
    And I choose "Edit" in the open action menu
    And I follow "Expand all"
    Then the field "Credits" in the "Testing" "fieldset" matches value "-50"

  @javascript
  Scenario: Check the credit field setup warning message.
    Given I log in as "admin"
    And I am on "Test" course homepage with editing mode on
    And I click on "Edit" "link" in the ".modtype_pulse" "css_element"
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    And I click on ".collapseexpand" "css_element" in the "#mod-pulse-form" "css_element"
    And the "options[credits]" "field" should be disabled
    When I set the field "options[credits_status]" to "1"
    Then the "options[credits]" "field" should be enabled
    And I set the field "options[credits]" to "500"
    And I set the field "Send Pulse notification" to "0"
    And I press "Save and return to course"
    When I navigate to "Plugins > Activity modules > Pulse > Pulse credits" in site administration
    Then I set the field "Credits user profile field" to "Choose"
    And I press "Save changes"
    When I am on "Test" course homepage with editing mode on
    And I click on "Edit" "link" in the ".modtype_pulse" "css_element"
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    And I click on ".collapseexpand" "css_element" in the "#mod-pulse-form" "css_element"
    Then I should see "you need to configure the user profile field first."
