@mod @mod_pulse @pulseaddon @pulseaddon_reminder

Feature: Check additional reminder features are works with pulse activity
  In order to check additional reminder features works
  As a teacher
  I should create pulse activity

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student    | User 1 | student1@test.com |
      | teacher1 | Teacher   | User 1 | teacher1@test.com |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion | showcompletionconditions |
      | Course 1 | C1        | 0        | 1                | 1                        |
    And the following "course enrolments" exist:
      | user | course | role           |
      | student1 | C1 | student        |
      | teacher1 | C1 | editingteacher |
    And the following "activity" exists:
      | activity    | pulse            |
      | course      | C1               |
      | idnumber    | pulse1           |
      | name        | Test pulse 1     |
      | intro       | Test pulse content |
      | pulse       | 0                |
    And I log in as "teacher1"

  @javascript
  Scenario: Pulse activity should extend the reminder feature forms.
    Given I am on "Course 1" course homepage with editing mode on
    And I click on "Edit" "link" in the ".modtype_pulse" "css_element"
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    When I expand all fieldsets
    And I should see "Reaction"
    And I should see "First reminder"
    And I should see "Second reminder"
    And I should see "Recurring reminder"
    And I click on "[name='pulse'][type='checkbox']" "css_element"
    And I press "Save and return to course"
    Then "Test pulse content" activity should be visible
    And I turn editing mode off
    And "Test pulse content" activity should be visible
    And I log out
    And I log in as "student1"
    And I am on "Course 1" course homepage
    And I should see "Test pulse content"
    And I log out
