# SilverStripe Integration for Behat

## Overview

[Behat](http://behat.org) is a testing framework for behaviour-driven development.
Because it primarily interacts with your website through a browser, 
you don't need any specific integration tools to get it going with
a basic SilverStripe website, simply follow the 
[standard Behat usage instructions](http://docs.behat.org/).

This extension comes in handy if you want to go beyond
interacting with an existing website and database,
for example make changes to your database content which
would need to be rolled back to a "clean slate" later on.

It provides the following helpers:

 * Provide access to SilverStripe classes in your Behat contexts
 * Set up a temporary database automatically
 * Reset the database content on every scenario
 * Prebuilt Contexts for SilverStripe's login forms and other common tasks
 * Creating of member fixtures with predefined permissions
 * YML fixture definitions inside your Behat scenarios
 * Waiting for jQuery Ajax responses (rather than fixed wait timers)
 * Captures JavaScript errors and logs them through Selenium
 * Saves screenshots to filesystem whenever an assertion error is detected

In order to achieve this, the extension makes one basic assumption:
Your Behat tests are run from the same application as the tested
SilverStripe codebase, on a locally hosted website from the same codebase.
This is important because we need access to the underlying SilverStripe
PHP classes. You can of course use a remote browser to do the actual testing.

Note: The extension has only been tested with the `selenium2` Mink driver.

## Installation

Simply [install SilverStripe through Composer](http://doc.silverstripe.org/framework/en/installation/composer).
Skip this step if adding the module to an existing project.

	composer create-project silverstripe/installer my-test-project 3.1.x-dev

Switch to the newly created webroot, and add the SilverStripe Behat extension.

	cd my-test-project
	composer require silverstripe/behat-extension:*

Now get the latest Selenium2 server (requires Java):

	wget https://selenium.googlecode.com/files/selenium-server-standalone-2.35.0.jar

We need to generate a token so the browser and commandline calls can interact with a "shared secret":

	php framework/cli-script.php dev/generatesecuretoken path=mysite/_config/behat.yml

Now install the SilverStripe project as usual by opening it in a browser and following the instructions.
Protip: You can skip this step by using `[SS_DATABASE_CHOOSE_NAME]` in a global 
[_ss_environment.php](http://doc.silverstripe.org/framework/en/topics/environment-management) 
file one level above the webroot.

Unless you have [$_FILE_TO_URL_MAPPING](http://doc.silverstripe.org/framework/en/topics/commandline#configuration)
set up, you also need to specify the URL for your webroot. Either add it to the existing `behat.yml` configuration file
in your project root, or set is as an environment variable in your terminal session: 

	export BEHAT_PARAMS="extensions[SilverStripe\BehatExtension\MinkExtension][base_url]=http://localhost/"

## Usage

### Starting the Selenium Server

You can run the server locally in a separate Terminal session:

    java -jar selenium-server-standalone-2.35.0.jar

### Running the Tests

Now you can run the tests (for example for the `framework` module):

	vendor/bin/behat @framework

In order to run specific tests only, use their feature file name:

	vendor/bin/behat @framework/login.feature

This will start a Firefox browser by default. Other browsers and profiles can be configured in `behat.yml`.

## Configuration

The SilverStripe installer already comes with a YML configuration
which is ready to run tests on a locally hosted Selenium server,
located in the project root as `behat.yml`.

You'll need to customize at least the `base_url` setting to match the URL where
the tested SilverStripe instance is hosted locally.  This 

Generic Mink configuration settings are placed in `SilverStripe\BehatExtension\MinkExtension`,
which is a subclass of `Behat\MinkExtension\Extension`.

Overview of settings (all in the `extensions.SilverStripe\BehatExtension\Extension` path):

 * `framework_path`: Path to the SilverStripe Framework folder. It supports both absolute and relative (to `behat.yml` file) paths.
 * `extensions.Behat\MinkExtension\Extension.base_url`: You will probably need to change the base URL that is used during the test process.
It is used every time you use relative URLs in your feature descriptions.
It will also be used by [file to URL mapping](http://doc.silverstripe.org/framework/en/topics/commandline#configuration) in `SilverStripeExtension`.
 * `extensions.Behat\MinkExtension\Extension.files_path`: Change to support file uploads in your tests.  Currently only absolute paths are supported.
 * `ajax_steps`: Because SilverStripe uses AJAX requests quite extensively, we had to invent a way
to deal with them more efficiently and less verbose than just
Optional `ajax_steps` is used to match steps defined there so they can be "caught" by
[special AJAX handlers](http://blog.scur.pl/2012/06/ajax-callback-support-behat-mink/) that tweak the delays. You can either use a pipe delimited string or a list of substrings that match step definition.
 * `ajax_timeout`: Milliseconds after which an Ajax request is regarded as timed out, 
 and the script continues with its assertions to avoid a deadlock (Default: 5000).
 * `screenshot_path`: Absolute path used to store screenshot of a last known state
of a failed step. 
Screenshot names within that directory consist of feature file filename and line
number that failed.

Example: behat.yml 

	default:
	  context:
	    class: SilverStripe\MyModule\Test\Behaviour\FeatureContext
	  extensions:
	    SilverStripe\BehatExtension\Extension:
	      screenshot_path: %behat.paths.base%/artifacts/screenshots
	    SilverStripe\BehatExtension\MinkExtension:
	      # Adjust this to your local environment
	      base_url:  http://localhost/
	      default_session: selenium2
	      javascript_session: selenium2
	      goutte: ~
	      selenium2:
	        browser: firefox

### Available Step Definitions

The extension comes with several `BehatContext` subclasses come with some extra step defintions.
Some of them are just helpful in general website testing, other's are specific to SilverStripe. 
To find out all available steps (and the files they are defined in), run the following:

	vendor/bin/behat @mymodule --definitions=i

Note: There are more specific step definitions in the SilverStripe `framework` module
for interacting with the CMS interfaces (see `framework/tests/behat/features/bootstrap`).
In addition to the dynamic list, a cheatsheet of available steps can be found at the end of this guide.

## Fixtures

Since each test run creates a new database, you can't rely on existing state unless
you explicitly define it. 

### Database Defaults

The easiest way to get default data is through `DataObject->requireDefaultRecords()`.
Many modules already have this method defined, e.g. the `blog` module automatically
creates a default `BlogHolder` entry in the page tree. Sometimes these defaults can
be counterproductive though, so you need to "opt-in" to them, via the `@database-defaults`
tag placed at the top of your feature definition. The defaults are reset after each
scenario automatically.

### Inline Definition

If you need more flexibility and transparency about which records are being created,
use the inline definition syntax. The following example shows some syntax variations:

	Feature: Do something with pages
		As an site owner
		I want to manage pages

		Background:
			# Creates a new page without data. Can be accessed later under this identifier
			Given a "page" "Page 1" 
			# Uses a custom RegistrationPage type
			And an "error page" "Register" 
			# Creates a page with inline properties 
			And a "page" "Page 2" with "URLSegment"="page-1" and "Content"="my page 1" 
			# Field names can be tabular, and based on DataObject::$field_labels 
			And the "page" "Page 3" has the following data
			 | Content | <blink> |
			 | My Property | foo |
			 | My Boolean | bar |
			# Pages are published by default, can be explicitly unpublished
			And the "page" "Page 1" is not published 
			# Create a hierarchy, and reference a record created earlier
			And the "page" "Page 1.1" is a child of a "page" "Page 1" 
			# Specific page type step 
			And a "page" "My Redirect" which redirects to a "page" "Page 1" 
			And a "member" "Website User" with "FavouritePage"="=>Page.Page 1"

		@javascript
		Scenario: View a page in the tree
			Given I am logged in with "ADMIN" permissions
			And I go to "/admin/pages"
			Then I should see "Page 1" in CMS Tree

 * Fixtures are created where you defined them. If you want the fixtures to be created
   before every scenario, define them in [Background](http://docs.behat.org/guides/1.gherkin.html#backgrounds). 
   If you want them to be created only when a particular scenario runs, define them there.
 * Fixtures are cleared between scenarios. 
 * The basic syntax works for all `DataObject` subclasses, but some specific
   notations like "is not published" requires extensions like `Hierarchy` to be applied to the class
 * Record types, identifiers, property names and property values need to be quoted
 * Record types (class names) can use more natural notation ("registration page" instead of "Registration Page")
 * Record types support the `$singular_name` notation which is also used to reference the types throughout the CMS.
   Record property names support the `$field_labels` notation in the same fashion.
 * Property values may also use a `=>` symbol to indicate relationships between records.
   The notation is `=><classname>.<identifier>`. For `has_many` or `many_many` relationships,
   multiple relationships can be separated by a comma.

## Writing Behat Tests

### Directory Structure

As a convention, SilverStripe Behat tests live in a `tests/behat` subfolder
of your module. You can create it with the following command:

	mkdir -p mymodule/tests/behat/features/bootstrap/MyModule/Test/Behaviour

### FeatureContext

The generic [Behat usage instructions](http://docs.behat.org/) apply
here as well. The only major difference is the base class from which
to extend your own `FeatureContext`: It should be `SilverStripeContext`
rather than `BehatContext`.

Example: mymodule/tests/behat/features/bootstrap/MyModule/Test/Behaviour/FeatureContext.php

	<?php
	namespace MyModule\Test\Behaviour;

	use SilverStripe\BehatExtension\Context\SilverStripeContext,
	    SilverStripe\BehatExtension\Context\BasicContext,
	    SilverStripe\BehatExtension\Context\LoginContext;

	require_once 'PHPUnit/Autoload.php';
	require_once 'PHPUnit/Framework/Assert/Functions.php';

	class FeatureContext extends SilverStripeContext
	{
	    public function __construct(array $parameters)
	    {
	        $this->useContext('BasicContext', new BasicContext($parameters));
	        $this->useContext('LoginContext', new LoginContext($parameters));

	        parent::__construct($parameters);
	    }
	}

## FAQ

### FeatureContext not found

This is most likely a problem with Composer's autoloading generator.
Check that you have "SilverStripe" mentioned in the `vendor/composer/autoload_classmap.php` file,
and call `composer dump-autoload` if not.

### Why does the module need to know about the framework path on the filesystem?

Sometimes SilverStripe needs to know the URL of your site. When you're visiting
your site in a web browser this is easy to work out, but if you're executing
scripts on the command-line, it has no way of knowing.

To work this out, this module is using [file to URL mapping](http://doc.silverstripe.org/framework/en/topics/commandline#configuration).

### How does the module interact with the SS database?

The module creates temporary database on init and is switching to the alternative
database session before every scenario by using `/dev/tests/setdb` TestRunner
endpoint.

It also populates this temporary database with the default records if necessary.

It is possible to include your own fixtures, it is explained further.

### Why do tests pass in a fresh installation, but fail in my own project?

Because we're testing the interface directly, any changes to the
viewed elements have the potential to disrupt testing. 
By building a test database from scratch, we're trying to minimize this impact.
Some examples where things can go wrong nevertheless:

 * Thirdparty SilverStripe modules which install default data
 * Changes to the default interface language
 * Configurations which remove admin areas or specific fields

Currently there's no way to exclude offending modules from a test run.
You either have to adjust the tests to work around these changes,
or run tests on a "sandbox" projects without these modules.

### How do I debug when something goes wrong?

First, read the console output. Behat will tell you which steps have failed.

SilverStripe Behaviour Testing Framework also notifies you about some events.
It tries to catch some JavaScript errors and AJAX errors as well although it
is limited to errors that occur after the page is loaded.

Screenshot will be taken by the module every time the step is marked as failed.
Refer to configuration section above to know how to set up the screenshot path.

If you are unable to debug using the information collected with the above
methods, it is possible to delay the step execution by adding the following step:

    And I wait for "10000"

where `10000` is the number of millisecods you wish the session to wait.
It is very useful when you want to look at the error or developer console
inside the browser or if you want to interact with the session page manually.

### How do I use SauceLabs.com for remote Selenium2 testing?

Here's a sample profile for your `behat.yml`:

	# Saucelabs.com sample setup, use with "vendor/bin/behat --profile saucelabs"
	saucelabs:
	  extensions:
	    SilverStripe\BehatExtension\MinkExtension:
	      selenium2:
	        browser: firefox
	        # Add your own username and API token here
	        wd_host: <user>:<api-token>@ondemand.saucelabs.com/wd/hub
	        capabilities:
	          platform: "Windows 2008"
	          browser: "firefox"
	          version: "15"

## Cheatsheet

This is a manually categorized list of available commands
when both the `cms` and `framework` modules are installed.
It's based on the `vendor/bin/behat -di @cms` output.

### Basics

	 Then /^(?:|I )should see "(?P<text>(?:[^"]|\\")*)"$/
	    - Checks, that page contains specified text.

	 Then /^(?:|I )should not see "(?P<text>(?:[^"]|\\")*)"$/
	    - Checks, that page doesn't contain specified text.

	 Then /^(?:|I )should see text matching (?P<pattern>"(?:[^"]|\\")*")$/
	    - Checks, that page contains text matching specified pattern.

	 Then /^(?:|I )should not see text matching (?P<pattern>"(?:[^"]|\\")*")$/
	    - Checks, that page doesn't contain text matching specified pattern.

	 Then /^the response should contain "(?P<text>(?:[^"]|\\")*)"$/
	    - Checks, that HTML response contains specified string.

	 Then /^the response should not contain "(?P<text>(?:[^"]|\\")*)"$/
	    - Checks, that HTML response doesn't contain specified string.

	 Then /^(?:|I )should see "(?P<text>(?:[^"]|\\")*)" in the "(?P<element>[^"]*)" element$/
	    - Checks, that element with specified CSS contains specified text.

	 Then /^(?:|I )should not see "(?P<text>(?:[^"]|\\")*)" in the "(?P<element>[^"]*)" element$/
	    - Checks, that element with specified CSS doesn't contain specified text.

	 Then /^the "(?P<element>[^"]*)" element should contain "(?P<value>(?:[^"]|\\")*)"$/
	    - Checks, that element with specified CSS contains specified HTML.

	 Then /^(?:|I )should see an? "(?P<element>[^"]*)" element$/
	    - Checks, that element with specified CSS exists on page.

	 Then /^(?:|I )should not see an? "(?P<element>[^"]*)" element$/
	    - Checks, that element with specified CSS doesn't exist on page.

	 Then /^(?:|I )should be on "(?P<page>[^"]+)"$/
	    - Checks, that current page PATH is equal to specified.

	 Then /^the (?i)url(?-i) should match (?P<pattern>"([^"]|\\")*")$/
	    - Checks, that current page PATH matches regular expression.

	 Then /^the response status code should be (?P<code>\d+)$/
	    - Checks, that current page response status is equal to specified.

	 Then /^the response status code should not be (?P<code>\d+)$/
	    - Checks, that current page response status is not equal to specified.

	 Then /^(?:|I )should see (?P<num>\d+) "(?P<element>[^"]*)" elements?$/
	    - Checks, that (?P<num>\d+) CSS elements exist on the page

	 Then /^print last response$/
	    - Prints last response to console.

	 Then /^show last response$/
	    - Opens last response content in browser.

	 Then /^I should be redirected to "([^"]+)"/

	Given /^I wait (?:for )?([\d\.]+) second(?:s?)$/

### Navigation

	Given /^(?:|I )am on homepage$/
	    - Opens homepage.

	 When /^(?:|I )go to homepage$/
	    - Opens homepage.

	Given /^(?:|I )am on "(?P<page>[^"]+)"$/
	    - Opens specified page.

	 When /^(?:|I )go to "(?P<page>[^"]+)"$/
	    - Opens specified page.

	 When /^(?:|I )reload the page$/
	    - Reloads current page.

	 When /^(?:|I )move backward one page$/
	    - Moves backward one page in history.

	 When /^(?:|I )move forward one page$/
	    - Moves forward one page in history

### Forms

	When /^(?:|I )press "(?P<button>(?:[^"]|\\")*)"$/
	    - Presses button with specified id|name|title|alt|value.

	 When /^(?:|I )follow "(?P<link>(?:[^"]|\\")*)"$/
	    - Clicks link with specified id|title|alt|text.

	 When /^(?:|I )fill in "(?P<field>(?:[^"]|\\")*)" with "(?P<value>(?:[^"]|\\")*)"$/
	    - Fills in form field with specified id|name|label|value.

	 When /^(?:|I )fill in "(?P<value>(?:[^"]|\\")*)" for "(?P<field>(?:[^"]|\\")*)"$/
	    - Fills in form field with specified id|name|label|value.

	 When /^(?:|I )fill in the following:$/
	    - Fills in form fields with provided table.

	 When /^(?:|I )select "(?P<option>(?:[^"]|\\")*)" from "(?P<select>(?:[^"]|\\")*)"$/
	    - Selects option in select field with specified id|name|label|value.

	 When /^(?:|I )additionally select "(?P<option>(?:[^"]|\\")*)" from "(?P<select>(?:[^"]|\\")*)"$/
	    - Selects additional option in select field with specified id|name|label|value.

	 When /^(?:|I )check "(?P<option>(?:[^"]|\\")*)"$/
	    - Checks checkbox with specified id|name|label|value.

	 When /^(?:|I )uncheck "(?P<option>(?:[^"]|\\")*)"$/
	    - Unchecks checkbox with specified id|name|label|value.

	 When /^(?:|I )attach the file "(?P[^"]*)" to "(?P<field>(?:[^"]|\\")*)"$/
	    - Attaches file to field with specified id|name|label|value.

	 Then /^the "(?P<field>(?:[^"]|\\")*)" field should contain "(?P<value>(?:[^"]|\\")*)"$/
	    - Checks, that form field with specified id|name|label|value has specified value.

	 Then /^the "(?P<field>(?:[^"]|\\")*)" field should not contain "(?P<value>(?:[^"]|\\")*)"$/
	    - Checks, that form field with specified id|name|label|value doesn't have specified value.

	 Then /^the "(?P<checkbox>(?:[^"]|\\")*)" checkbox should be checked$/
	    - Checks, that checkbox with specified in|name|label|value is checked.

	 Then /^the "(?P<checkbox>(?:[^"]|\\")*)" checkbox should not be checked$/
	    - Checks, that checkbox with specified in|name|label|value is unchecked.

	Given /^(?:|I )attach the file "(?P[^"]*)" to "(?P<field>(?:[^"]|\\")*)" with HTML5$/

	When /^I fill in the "(?P<field>([^"]*))" HTML field with "(?P<value>([^"]*))"$/

	 When /^I fill in "(?P<value>([^"]*))" for the "(?P<field>([^"]*))" HTML field$/

	 When /^I append "(?P<value>([^"]*))" to the "(?P<field>([^"]*))" HTML field$/

	 Then /^the "(?P<locator>([^"]*))" HTML field should contain "(?P<html>([^"]*))"$/

	When /^(?:|I )fill in "(?P<field>(?:[^"]|\\")*)" dropdown with "(?P<value>(?:[^"]|\\")*)"$/
	  - Workaround for chosen.js dropdowns or tree dropdowns which hide the original dropdown field.

	When /^(?:|I )fill in "(?P<value>(?:[^"]|\\")*)" for "(?P<field>(?:[^"]|\\")*)" dropdown$/
	  - Workaround for chosen.js dropdowns or tree dropdowns which hide the original dropdown field.



### Interactions

	Given /^I press the "([^"]*)" button$/

	Given /^I click "([^"]*)" in the "([^"]*)" element$/

	Given /^I type "([^"]*)" into the dialog$/

	Given /^I confirm the dialog$/

	Given /^I dismiss the dialog$/

### Login

	Given /^I am logged in with "([^"]*)" permissions$/
	    - Creates a member in a group with the correct permissions.

	Given /^I am not logged in$/

	 When /^I log in with "(?<username>[^"]*)" and "(?<password>[^"]*)"$/

	Given /^I should see a log-in form$/

	 Then /^I will see a bad log-in message$/

### CMS UI

	 Then /^I should see an edit page form$/
	 
	 Then /^I should see the CMS$/

	 Then /^I should see a "([^"]*)" notice$/

	 Then /^I should see a "([^"]*)" message$/

	Given /^I should see a "([^"]*)" button in CMS Content Toolbar$/

	 When /^I should see "([^"]*)" in CMS Tree$/

	 When /^I should not see "([^"]*)" in CMS Tree$/

	 When /^I expand the "([^"]*)" CMS Panel$/

	 When /^I click the "([^"]*)" CMS tab$/

	 Then /^the "([^"]*)" table should contain "([^"]*)"$/

	 Then /^the "([^"]*)" table should not contain "([^"]*)"$/

	Given /^I click on "([^"]*)" in the "([^"]*)" table$/

	 Then /^I can see the preview panel$/

	Given /^the preview contains "([^"]*)"$/

	Given /^the preview does not contain "([^"]*)"$/


### Fixtures

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" (:?which )?redirects to (?:(an|a|the) )"(?<targetType>[^"]+)" "(?<targetId>[^"]+)"$/
	    - Find or create a redirector page and link to another existing page.

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)"$/
	    - Example: Given a "page" "Page 1"

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" with (?<data>.*)$/
	    - Example: Given a "page" "Page 1" with "URL"="page-1" and "Content"="my page 1"

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" has the following data$/
	    - Example: And the "page" "Page 2" has the following data

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" is a (?<relation>[^\s]*) of (?:(an|a|the) )"(?<relationType>[^"]+)" "(?<relationId>[^"]+)"/
	    - Example: Given the "page" "Page 1.1" is a child of the "page" "Page1"

	Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" is (?<state>[^"]*)$/
	    - Example: Given the "page" "Page 1" is not published

	Given /^there are the following ([^\s]*) records$/
	    - Accepts YAML fixture definitions similar to the ones used in SilverStripe unit testing.

	Given /^(?:(an|a|the) )"member" "(?<id>[^"]+)" belonging to "(?<groupId>[^"]+)"$/
	    - Example: Given a "member" "Admin" belonging to "Admin Group"

	Given /^(?:(an|a|the) )"member" "(?<id>[^"]+)" belonging to "(?<groupId>[^"]+)" with (?<data>.*)$/

	Given /^(?:(an|a|the) )"group" "(?<id>[^"]+)" (?:(with|has)) permissions (?<permissionStr>.*)$/
	    - Example: Given a "group" "Admin" with permissions "Access to 'Pages' section" and "Access to 'Files' section"
	    # SilverStripe\Cms\Test\Behaviour\FixtureContext::stepCreateGroupWithPermissions()

## Useful resources

* [SilverStripe CMS architecture](http://doc.silverstripe.org/sapphire/en/trunk/reference/cms-architecture)
* [SilverStripe Framework Test Module](https://github.com/silverstripe-labs/silverstripe-frameworktest)
* [SilverStripe Unit and Integration Testing](http://doc.silverstripe.org/sapphire/en/trunk/topics/testing)
