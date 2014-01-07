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

In order to achieve this, the extension makes on basic assumption:
Your Behat tests are run from the same application as the tested
SilverStripe codebase, on a locally hosted website from the same codebase.
This is important because we need access to the underlying SilverStripe
PHP classes. You can of course use a remote browser to do the actual testing.

Note: The extension has only been tested with the `selenium2` Mink driver.

## Installation

As a first step, [install SilverStripe 3.x through Composer](http://doc.silverstripe.org/framework/en/installation/composer).

Then require this extension:

	phar composer.phar require silverstripe/behat-extension:*

Install PHPUnit through PEAR:

	pear channel-discover pear.phpunit.de
	pear install phpunit/PHPUnit

And get the latest Selenium2 server (requires Java):

	wget http://selenium.googlecode.com/files/selenium-server-standalone-2.39.0.jar
	
*If you are having issues running Selenium with your browser please check https://code.google.com/p/selenium/downloads/list as the Selenium driver could be out of date*

## Configuration

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

### behat.yml

Create a `behat.yml` file in the project root, and add the template below.

TODO: Move to auto-loaded configuration

Example: behat.yml 

	default:
	  context:
      class: SilverStripe\MyModule\Test\Behaviour\FeatureContext
	  extensions:
	    SilverStripe\BehatExtension\Extension: ~
	    Behat\MinkExtension\Extension:
	      # Adjust this to your local environment
	      base_url:  http://localhost/
	      default_session: selenium2
	      javascript_session: selenium2
	      goutte: ~
	      selenium2:
	        browser: firefox

You'll need to customize at least the `base_url` setting to match the URL where
the tested SilverStripe instance is hosted locally. 
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
 * `screenshot_path`: Used to store screenshot of a last known state
of a failed step. It defaults to whatever is returned by PHP's `sys_get_temp_dir()`.
Screenshot names within that directory consist of feature file filename and line
number that failed.

## Usage

### Starting the selenium server

You can either run the server in a separate Terminal tab:

    java -jar selenium-server-standalone-2.39.0.jar

Or you can run it in the background:

    java -jar selenium-server-standalone-2.39.0.jar > /dev/null &


### Running the tests

You will have Behat binary located in `bin` directory in your project root (or where `composer.json` is located).

By default, Behat will use Selenium2 driver.
Selenium will also try to use chrome browser. Refer to `behat.yml` for details.

    # Run all "mymodule" tests
    vendor/bin/behat @mymodule

    # Run a specific feature test
    vendor/behat @mymodule/my-steps.feature

### Available Step Definitions

The extension comes with several `BehatContext` subclasses come with some extra step defintions.
Some of them are just helpful in general website testing, other's are specific to SilverStripe. 
To find out all available steps (and the files they are defined in), run the following:

	vendor/bin/behat @mymodule --definitions=i

Note: There are more specific step definitions in the SilverStripe `framework` module
for interacting with the CMS interfaces (see `framework/tests/behat/features/bootstrap`).

### Fixtures

Fixtures should be provided in YAML format (standard SilverStripe fixture format)
as [PyStrings](http://docs.behat.org/guides/1.gherkin.html#pystrings)

Take a look at the sample fixture logic first:

    Given there are the following Permission records
      """
      admin:
        Code: ADMIN
      """
    And there are the following Group records
      """
      admingroup:
        Title: Admin Group
        Code: admin
        Permissions: =>Permission.admin
      """
    And there are the following Member records
      """
      admin:
        FirstName: Admin
        Email: admin@test.com
        Groups: =>Group.admingroup
      """

In this example, the fixture is used to create Admin member with admin permissions.

As you can see, there are special Gherkin steps that take care of loading
fixtures into database. They use the following format:

    Given there are the following TableName records
      """
      RowIdentifier:
        ColumnName: Value
      """

Fixtures may also use a `=>` symbol to indicate relationships between records.
In the example above `=>Permission.admin` will be replaced with row `ID` of a
`Permission` record that has `RowIdentifier` set as `admin`.

Fixtures are created where you defined them. If you want the fixtures to be created
before every scenario, define them in [Background](http://docs.behat.org/guides/1.gherkin.html#backgrounds). If you want them to be created only when a particular scenario runs, define them there.

Fixtures are usually not cleared between scenarios. You can alter this behaviour
by tagging the feature or scenario with `@database-defaults` tag.

The module runner empties the database before each scenario tagged with
`@database-defaults` and populates it with default records (usually a set of
default pages).

## Howto

### Additional profiles

By default, `MinkExtension` is using `FirefoxDriver`.
Let's say you want to use `ChromeDriver` too.

You can either override the `selenium2` setting in default profile or add another
profile that can be run using `bin/behat --profile=PROFILE_NAME`, where `PROFILE_NAME`
could be `chrome`.

    chrome:
      extensions:
          Behat\MinkExtension\Extension:
            selenium2:
              capabilities:
                browserName: chrome
                version: ANY

## FAQ

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
	    Behat\MinkExtension\Extension:
	      selenium2:
	        browser: firefox
	        # Add your own username and API token here
	        wd_host: <user>:<api-token>@ondemand.saucelabs.com/wd/hub
	        capabilities:
	          platform: "Windows 2008"
	          browser: "firefox"
	          version: "15"

## Useful resources

* [SilverStripe CMS architecture](http://doc.silverstripe.org/sapphire/en/trunk/reference/cms-architecture)
* [SilverStripe Framework Test Module](https://github.com/silverstripe-labs/silverstripe-frameworktest)
* [SilverStripe Unit and Integration Testing](http://doc.silverstripe.org/sapphire/en/trunk/topics/testing)
