@block @block_crucible
Feature: The Applications block can be placed, configured and administered on a fresh site
  In order to rely on the Applications block before any Crucible service is connected
  As a user and as an administrator
  I can see the block on the Dashboard, choose a view for it, and open its settings

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | user1    | User      | One      | user1@example.com |
    And the following "blocks" exist:
      | blockname | contextlevel | reference | pagetypepattern | defaultregion |
      | crucible  | System       | 1         | my-index        | content       |

  @javascript
  Scenario: An unconfigured block asks to be configured on the Dashboard
    When I log in as "user1"
    Then "block_crucible" "block" should exist
    And I should see "This block needs to be configured." in the "block_crucible" "block"
    And I should not see "Configure this block" in the "block_crucible" "block"

  Scenario: Choosing the Competencies view renders it in place of the prompt
    Given I log in as "user1"
    And I turn editing mode on
    And I should see "This block needs to be configured." in the "block_crucible" "block"
    When I follow "Configure this block"
    And I set the field "Choose view" to "Competencies"
    And I press "Save changes"
    Then I should see "No competencies are currently mapped to courses or activities." in the "block_crucible" "block"
    And I should not see "This block needs to be configured." in the "block_crucible" "block"

  Scenario: Choosing the Applications view on a site that has not enabled it says so
    Given I log in as "user1"
    And I turn editing mode on
    When I follow "Configure this block"
    And I set the field "Choose view" to "Applications"
    And I press "Save changes"
    Then I should see "The Applications plugin has not been enabled" in the "block_crucible" "block"
    And I should see "Please contact your system administrator to configure and enable the plugin." in the "block_crucible" "block"

  Scenario: The site settings page loads, saves, and links to the application list
    Given I log in as "admin"
    When I visit "/admin/settings.php?section=blocksettingcrucible"
    Then I should see "Crucible Applications"
    And I should see "Organization Role Sync"
    And I press "Save changes"
    And I should see "Changes saved"
    And I follow "Add or edit applications"
    And I should see "Manage Applications"
    And I should see "No applications have been added yet."
