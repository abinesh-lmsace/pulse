@mod @mod_pulse @pulseaddon @pulseaddon_report

Feature: Check pulsepro features are works with pulse activity
  In order to check pulsepro features works
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
  Scenario: Pulse reports should display mark by self completion methods.
    Given I am on "Course 1" course homepage with editing mode on
    And I open "Test pulse content" actions menu
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    When I expand all fieldsets
    And I set the following fields to these values:
      | Add requirements | 1 |
      | Mark as complete by student to complete this activity | 1 |
      | Send Pulse notification | 0 |
    And I press "Save and return to course"
    When I navigate to "Reports > Pulse Reports" in current page administration
    And I click on "View report" "link" in the "Test pulse 1" "table_row"
    Then I should see "Self" in the "Student User 1" "table_row"

  @javascript
  Scenario: Pulse reports should display require approval completion methods.
    Given I am on "Course 1" course homepage with editing mode on
    And I open "Test pulse content" actions menu
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    When I expand all fieldsets
    And I set the following fields to these values:
    | Send Pulse notification | 0 |
    | Add requirements | 1 |
    | Require approval by one of the following roles | 1 |
    And I click on ".form-autocomplete-downarrow" "css_element" in the "#fgroup_id_completionrequireapproval" "css_element"
    And I click on "Teacher" "list_item" in the "#fgroup_id_completionrequireapproval [class='form-autocomplete-suggestions']" "css_element"
    And I press "Save and return to course"
    When I navigate to "Reports > Pulse Reports" in current page administration
    And I click on "View report" "link" in the "Test pulse 1" "table_row"
    Then I should see "Teacher" in the "Student User 1" "table_row"
    And ".approved-completion" "css_element" should exist in the "Student User 1" "table_row"

  @javascript
  Scenario: Pulse reports should display selected completion methods.
    Given I am on "Course 1" course homepage with editing mode on
    And I open "Test pulse content" actions menu
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    When I expand all fieldsets
    And I set the following fields to these values:
      | Send Pulse notification | 0 |
      | Add requirements | 1 |
      | Require approval by one of the following roles | 1 |
      | Mark as complete by student to complete this activity | 1 |
    And I click on ".form-autocomplete-downarrow" "css_element" in the "#fgroup_id_completionrequireapproval" "css_element"
    And I click on "Teacher" "list_item" in the "#fgroup_id_completionrequireapproval [class='form-autocomplete-suggestions']" "css_element"
    And I press "Save and return to course"
    When I navigate to "Reports > Pulse Reports" in current page administration
    And I click on "View report" "link" in the "Test pulse 1" "table_row"
    Then I should see "Self" in the "Student User 1" "table_row"
    And I should see "Teacher" in the "Student User 1" "table_row"
    And ".approved-completion" "css_element" should exist in the "Student User 1" "table_row"
