@quiz @quiz_paperentry
Feature: Paper Entry quiz report
  As a teacher with manage capability
  I need to export answer sheets, import filled CSVs, and manage graders
  So that I can grade paper-based quiz attempts efficiently

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Test Course | TC1 |
    And the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | One | teacher1@example.com |
      | student1 | Student | One | student1@example.com |
      | student2 | Student | Two | student2@example.com |
      | grader1  | Grader  | One | grader1@example.com  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | TC1    | editingteacher |
      | student1 | TC1    | student        |
      | student2 | TC1    | student        |
      | grader1  | TC1    | teacher        |
    And the following "activities" exist:
      | activity | course | name       | idnumber |
      | quiz     | TC1    | Test Quiz  | quiz1    |
    And the following "questions" exist:
      | questioncategory | qtype       | name | questiontext      |
      | Test Quiz        | multichoice | Q1   | What is 1 + 1?    |
      | Test Quiz        | multichoice | Q2   | What is 2 + 2?    |
    And quiz "Test Quiz" contains the following questions:
      | question | page |
      | Q1       | 1    |
      | Q2       | 1    |

  @javascript
  Scenario: Manager can access the Paper Entry report
    Given I am on the "Test Quiz" "quiz activity" page logged in as "teacher1"
    When I navigate to "Results > Paper Entry" in current page administration
    Then I should see "Paper Entry"
    And I should see "Export Settings"
    And I should see "Graders"

  @javascript
  Scenario: Manager can add a grader
    Given I am on the "Test Quiz" "quiz activity" page logged in as "teacher1"
    And I navigate to "Results > Paper Entry" in current page administration
    When I select "Grader One (grader1@example.com)" from the "userid" singleselect
    And I press "Add grader"
    Then I should see "Grader added."
    And I should see "Grader One"

  @javascript
  Scenario: Manager can remove a grader
    Given I am on the "Test Quiz" "quiz activity" page logged in as "teacher1"
    And I navigate to "Results > Paper Entry" in current page administration
    And I select "Grader One (grader1@example.com)" from the "userid" singleselect
    And I press "Add grader"
    When I press "Remove"
    Then I should see "Grader removed."
    And I should not see "Grader One" in the ".card table" "css_element"

  @javascript
  Scenario: Manager sees shuffle warning when shuffle answers is enabled
    Given question "Q1" has the following options:
      | shuffleanswers | 1 |
    And I am on the "Test Quiz" "quiz activity" page logged in as "teacher1"
    When I navigate to "Results > Paper Entry" in current page administration
    Then I should see "Shuffle answers must be disabled before exporting"

  @javascript
  Scenario: Question options reference is collapsed by default for manager
    Given I am on the "Test Quiz" "quiz activity" page logged in as "teacher1"
    And I navigate to "Results > Paper Entry" in current page administration
    When I should see "Question options reference"
    Then the "div#paperentry-qref-manager" "css_element" should not be visible

  @javascript
  Scenario: Grader can see the report after being assigned
    Given I am on the "Test Quiz" "quiz activity" page logged in as "teacher1"
    And I navigate to "Results > Paper Entry" in current page administration
    And I select "Grader One (grader1@example.com)" from the "userid" singleselect
    And I press "Add grader"
    And I log out
    When I am on the "Test Quiz" "quiz activity" page logged in as "grader1"
    And I navigate to "Results > Paper Entry" in current page administration
    Then I should see "Download Answer Sheet"
    And I should see "Submit Your Answers"

  @javascript
  Scenario: Non-grader teacher cannot access the report
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher2 | Teacher | Two | teacher2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher2 | TC1    | teacher |
    When I am on the "Test Quiz" "quiz activity" page logged in as "teacher2"
    And I navigate to "Results > Paper Entry" in current page administration
    Then I should see "You do not have access to this report"
