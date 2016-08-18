<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\BehatContext;
use Behat\Behat\Context\Step;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;

// PHPUnit
require_once BASE_PATH . '/vendor/phpunit/phpunit/src/Framework/Assert/Functions.php';

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
    public function iAmLoggedInWithPermissions($permCode)
    {
        if (!isset($this->cache_generatedMembers[$permCode])) {
            $group = Group::get()->filter('Title', "$permCode group")->first();
            if (!$group) {
                $group = \Injector::inst()->create('SilverStripe\\Security\\Group');
            }

            $group->Title = "$permCode group";
            $group->write();

            $permission = \Injector::inst()->create('SilverStripe\\Security\\Permission');
            $permission->Code = $permCode;
            $permission->write();
            $group->Permissions()->add($permission);

            $member = DataObject::get_one('SilverStripe\\Security\\Member', sprintf('"Email" = \'%s\'', "$permCode@example.org"));
            if (!$member) {
                $member = \Injector::inst()->create('SilverStripe\\Security\\Member');
            }

            // make sure any validation for password is skipped, since we're not testing complexity here
            $validator = Member::password_validator();
            Member::set_password_validator(null);
            $member->FirstName = $permCode;
            $member->Surname = "User";
            $member->Email = "$permCode@example.org";
            $member->PasswordEncryption = "none";
            $member->changePassword('Secret!123');
            $member->write();
            $group->Members()->add($member);
            Member::set_password_validator($validator);

            $this->cache_generatedMembers[$permCode] = $member;
        }

        return new Step\Given(sprintf('I log in with "%s" and "%s"', "$permCode@example.org", 'Secret!123'));
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
        $loginUrl = $c->joinUrlParts($c->getBaseUrl(), $c->getLoginUrl());
        $this->getSession()->visit($loginUrl);
        $page = $this->getSession()->getPage();
        $forms = $page->findAll('xpath', '//form[contains(@action, "Security/LoginForm")]');
        assertNotNull($forms, 'Login form not found');

        // Try to find visible forms again on login page.
        $visibleForm = null;
        foreach ($forms as $form) {
            if ($form->isVisible() && $form->find('css', '[name=Email]')) {
                $visibleForm = $form;
            }
        }

        assertNotNull($visibleForm, 'Could not find login form');

        $emailField = $visibleForm->find('css', '[name=Email]');
        $passwordField = $visibleForm->find('css', '[name=Password]');
        $submitButton = $visibleForm->find('css', '[type=submit]');
        $securityID = $visibleForm->find('css', '[name=SecurityID]');

        assertNotNull($emailField, 'Email field on login form not found');
        assertNotNull($passwordField, 'Password field on login form not found');
        assertNotNull($submitButton, 'Submit button on login form not found');
        // @todo Once CSRF is mandatory, uncomment this
        // assertNotNull($securityID, 'CSRF token not found');

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
     * @Then /^I will see a "([^"]*)" log-in message$/
     */
    public function stepIWillSeeALogInMessage($type)
    {
        $page = $this->getSession()->getPage();
        $message = $page->find('css', sprintf('.message.%s', $type));
        assertNotNull($message, sprintf('%s message not found.', $type));
    }

    /**
     * @Then /^the password for "([^"]*)" should be "([^"]*)"$/
     */
    public function stepPasswordForEmailShouldBe($id, $password)
    {
        $member = Member::get()->filter('Email', $id)->First();
        assertNotNull($member);
        assertTrue($member->checkPassword($password)->valid());
    }
}
