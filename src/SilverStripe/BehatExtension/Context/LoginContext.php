<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\ClosuredContextInterface,
Behat\Behat\Context\TranslatedContextInterface,
Behat\Behat\Context\BehatContext,
Behat\Behat\Context\Step,
Behat\Behat\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode,
Behat\Gherkin\Node\TableNode;

// PHPUnit
require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * LoginContext
 *
 * Context used to define steps related to login and logout functionality
 */
class LoginContext extends BehatContext
{
    protected $context;

    /**
     * Cache for logInWithPermission()
     */
    protected $cache_generatedMembers = array();

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param array $parameters context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters)
    {
        // Initialize your context here
        $this->context = $parameters;
    }

    /**
     * Get Mink session from MinkContext
     */
    public function getSession($name = null)
    {
        return $this->getMainContext()->getSession($name);
    }

    /**
     * @Given /^I am logged in$/
     */
    public function stepIAmLoggedIn()
    {
        $c = $this->getMainContext();
        $adminUrl = $c->joinUrlParts($c->getBaseUrl(), $c->getAdminUrl());
        $loginUrl = $c->joinUrlParts($c->getBaseUrl(), $c->getLoginUrl());

        $this->getSession()->visit($adminUrl);

        if (0 == strpos($this->getSession()->getCurrentUrl(), $loginUrl)) {
            $this->stepILogInWith('admin', 'password');
            assertStringStartsWith($adminUrl, $this->getSession()->getCurrentUrl());
        }
    }

    /**
     * Creates a member in a group with the correct permissions.
     * Example: Given I am logged in with "ADMIN" permissions
     * 
     * @Given /^I am logged in with "([^"]*)" permissions$/
     */
    function iAmLoggedInWithPermissions($permCode)
    {
        if (!isset($this->cache_generatedMembers[$permCode])) {
            $group = \Injector::inst()->create('Group');
            $group->Title = "$permCode group";
            $group->write();

            $permission = \Injector::inst()->create('Permission');
            $permission->Code = $permCode;
            $permission->write();
            $group->Permissions()->add($permission);

            $member = \DataObject::get_one('Member', sprintf('"Email" = \'%s\'', "$permCode@example.org"));
            if (!$member) {
                $member = \Injector::inst()->create('Member');
            }

            $member->FirstName = $permCode;
            $member->Surname = "User";
            $member->Email = "$permCode@example.org";
            $member->changePassword('secret');
            $member->write();
            $group->Members()->add($member);

            $this->cache_generatedMembers[$permCode] = $member;
        }

//        $this->cache_generatedMembers[$permCode]->logIn();
        return new Step\Given(sprintf('I log in with "%s" and "%s"', "$permCode@example.org", 'secret'));
    }

    /**
     * @Given /^I am not logged in$/
     */
    public function stepIAmNotLoggedIn()
    {
        $c = $this->getMainContext();
        $this->getSession()->visit($c->joinUrlParts($c->getBaseUrl(), 'Security/logout'));
    }

    /**
     * @When /^I log in with "(?<username>[^"]*)" and "(?<password>[^"]*)"$/
     */
    public function stepILogInWith($email, $password)
    {
        $c = $this->getMainContext();

        // Make sure we're logged out first, as the state of already being logged
        // in and visiting the login page shows a warning "You don't have permission...",
        // but in that case there is no login form.
        $logoutUrl = $c->joinUrlParts($c->getBaseUrl(), 'Security/logout');
        $this->getSession()->visit($logoutUrl);

        $loginUrl = $c->joinUrlParts($c->getBaseUrl(), $c->getLoginUrl());
        $this->getSession()->visit($loginUrl);

        $page = $this->getSession()->getPage();

        $form = $page->find('css', 'form[action="Security/LoginForm"]');
        assertNotNull($form, 'Login form not found');

        $emailField = $page->find('css', '[name=Email]');
        $passwordField = $page->find('css', '[name=Password]');
        $submitButton = $page->find('css', '[type=submit]');

        assertNotNull($emailField, 'Email field on login form not found');
        assertNotNull($passwordField, 'Password field on login form not found');
        assertNotNull($submitButton, 'Submit button on login form not found');

        $emailField->setValue($email);
        $passwordField->setValue($password);
        $submitButton->press();
    }

    /**
     * @Given /^I should see a log-in form$/
     */
    public function stepIShouldSeeALogInForm()
    {
        $page = $this->getSession()->getPage();

        $loginForm = $page->find('css', '#MemberLoginForm_LoginForm');
        assertNotNull($loginForm, 'I should see a log-in form');
    }

    /**
     * @Then /^I will see a bad log-in message$/
     */
    public function stepIWillSeeABadLogInMessage()
    {
        $page = $this->getSession()->getPage();

        $badMessage = $page->find('css', '.message.bad');

        assertNotNull($badMessage, 'Bad message not found.');
    }

    /**
     * @Then /^the password for "([^"]*)" should be "([^"]*)"$/
     */
    public function stepPasswordForEmailShouldBe($id, $password)
    {
        $member = \Member::get()->filter('Email', $id)->First();
        assertNotNull($member);
        assertTrue($member->checkPassword($password)->valid());
    }
}
