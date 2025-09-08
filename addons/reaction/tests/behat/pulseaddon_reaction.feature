@mod @mod_pulse @pulseaddon @pulseaddon_reaction

Feature: Check pulsepro rate reactions are works with pulse activity
  In order to check pulsepro rate works
  As a teacher
  I should create pulse activity and set the reation as rate

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
  Scenario: View reactions availability in pulse.
    Given I am on "Course 1" course homepage with editing mode on
    And I open "Test pulse content" actions menu
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    When I expand all fieldsets
    Then the "Mark complete" "option" should be disabled
    And the "Approve" "option" should be disabled
    # Enable completion self completion rule.
    And I set the following fields to these values:
      | Add requirements | 1 |
    When I click on "Mark as complete by student to complete this activity" "checkbox"
    Then the "Mark complete" "option" should be enabled
    When I click on "Require approval by one of the following roles" "checkbox"
    And the "Approve" "option" should be enabled

  @javascript
  Scenario: View reactions in course page when the location set as content.
    Given I am on "Course 1" course homepage with editing mode on
    And I open "Test pulse content" actions menu
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    When I expand all fieldsets
    And I set the following fields to these values:
      | Type | Rate |
      | Location | Both |
      | Send Pulse notification | 0 |
    And I press "Save and return to course"
    And I wait until the page is ready
    Then I should see "Like" in the ".pulse-completion-btn" "css_element"
    And I log out
    # Student view
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I should see "Like" in the ".pulse-completion-btn" "css_element"
    # And I should found

  @javascript
  Scenario: Reactions not shown on course page when location set as notification only.
    Given I am on "Course 1" course homepage with editing mode on
    And I open "Test pulse content" actions menu
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    When I expand all fieldsets
    And I set the following fields to these values:
      | Type | Rate |
      | Location | Notification only |
      | Send Pulse notification | 0 |
    And I press "Save and return to course"
    Then I should not see "Like" in the ".modtype_pulse .activity-altcontent" "css_element"
    And I log out
    # Student view
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I should not see "Like" in the ".modtype_pulse .activity-altcontent" "css_element"

  @javascript
  Scenario: Use Rate reaction.
    Given I am on "Course 1" course homepage with editing mode on
    And I open "Test pulse content" actions menu
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    When I expand all fieldsets
    And I set the following fields to these values:
      | Type | Rate |
      | Location | Both |
      | Send Pulse notification | 0 |
    And I press "Save and return to course"
    And I wait until the page is ready
    Then I should see "Like" in the ".pulse-completion-btn" "css_element"
    And I log out
    # Student view
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I should see "Like" in the ".pulse-completion-btn" "css_element"
    And I click on "Like" "link"
    And I should see "Thank you! Your response is saved"
    Then I click on "Login and Go to course" "link"
    And I log out
    # Teacher view
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    When I navigate to "Reports > Pulse Reports" in current page administration
    And I click on "View report" "link" in the "Test pulse 1" "table_row"
    And I should see "Like" in the "Student User 1" "table_row"

  @javascript
  Scenario: Use mark complete reaction.
    Given I am on "Course 1" course homepage with editing mode on
    And I open "Test pulse content" actions menu
    And I click on ".menu-action-text" "css_element" in the ".modtype_pulse" "css_element"
    When I expand all fieldsets
    And I set the following fields to these values:
      | Add requirements | 1 |
      | Mark as complete by student to complete this activity | 1 |
      | Type | Mark complete |
      | Location | Both |
      | Send Pulse notification | 0 |
    And I press "Save and return to course"
    And I wait until the page is ready
    Then I should see "Mark Complete" in the ".pulse-completion-btn" "css_element"
    And I log out
    # Student view
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I should see "Mark Complete" in the ".pulse-completion-btn" "css_element"
    And I click on "Mark Complete" "link"
    Then I should see "Thank you! Your response is saved"
    And I click on "Login and Go to course" "link"
    And I log out
    # Teacher view
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    When I navigate to "Reports > Pulse Reports" in current page administration
    And I click on "View report" "link" in the "Test pulse 1" "table_row"
    And ".self-completion.badge.badge-success" "css_element" should exist in the "Student User 1" "table_row"
