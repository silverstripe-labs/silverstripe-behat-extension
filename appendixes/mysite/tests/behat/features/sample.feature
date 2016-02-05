Feature: View site links
  As a SS visitor
  I can visit the homepage
  So I can view SS products and services

  @jira:Sample-123 @smoke @sanity
  Scenario: Visit homepage
    Given I am on homepage
    Then I should see "Welcome to SilverStripe! This is the default homepage."
    When I go to "/about-us/"
    Then I should see "About Us"