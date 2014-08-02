<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\Context;
use Behat\Mink\Element\NodeElement;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * LoginContext
 *
 * Context used to define steps related to login and logout functionality
 */
class LoginContext implements Context
{
    use MainContextAwareTrait;

    /**
     * @Given /^I am logged in$/
     */
    public function stepIAmLoggedIn()
    {
        $c = $this->getMainContext();
        $adminUrl = $c->joinUrlParts($c->getBaseUrl(), $c->getAdminUrl());
        $loginUrl = $c->joinUrlParts($c->getBaseUrl(), $c->getLoginUrl());

        $this->getMainContext()->getSession()->visit($adminUrl);

        if (0 == strpos($this->getMainContext()->getSession()->getCurrentUrl(), $loginUrl)) {
            $this->stepILogInWith('admin', 'password');
            assertStringStartsWith($adminUrl, $this->getMainContext()->getSession()->getCurrentUrl());
        }
    }

    /**
     * Creates a member in a group with the correct permissions.
     * Example: Given I am logged in with "ADMIN" permissions
     *
     * @Given /^I am logged in with "([^"]*)" permissions$/
     * @param string $permCode
     */
    public function iAmLoggedInWithPermissions($permCode)
    {
        $email = "{$permCode}@example.org";
        $password = 'Secret!123';
        $this->generateMemberWithPermission($email, $password, $permCode);
        $this->stepILogInWith($email, $password);
    }

    /**
     * @Given /^I am not logged in$/
     */
    public function stepIAmNotLoggedIn()
    {
        $c = $this->getMainContext();
        $this->getMainContext()->getSession()->visit($c->joinUrlParts($c->getBaseUrl(), 'Security/logout'));
    }

    /**
     * @When /^I log in with "(?<username>[^"]*)" and "(?<password>[^"]*)"$/
     * @param string $email
     * @param string $password
     */
    public function stepILogInWith($email, $password)
    {
        $c = $this->getMainContext();
        $loginUrl = $c->joinUrlParts($c->getBaseUrl(), $c->getLoginUrl());
        $this->getMainContext()->getSession()->visit($loginUrl);
        $page = $this->getMainContext()->getSession()->getPage();
        $forms = $page->findAll('xpath', '//form[contains(@action, "Security/LoginForm")]');
        assertNotNull($forms, 'Login form not found');

        // Try to find visible forms again on login page.
        $visibleForm = null;
        /** @var NodeElement $form */
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
        assertNotNull($securityID, 'CSRF token not found');

        $emailField->setValue($email);
        $passwordField->setValue($password);
        $submitButton->press();
    }

    /**
     * @Given /^I should see a log-in form$/
     */
    public function stepIShouldSeeALogInForm()
    {
        $page = $this->getMainContext()->getSession()->getPage();
        $loginForm = $page->find('css', '#MemberLoginForm_LoginForm');
        assertNotNull($loginForm, 'I should see a log-in form');
    }

    /**
     * @Then /^I will see a "([^"]*)" log-in message$/
     * @param string $type
     */
    public function stepIWillSeeALogInMessage($type)
    {
        $page = $this->getMainContext()->getSession()->getPage();
        $message = $page->find('css', sprintf('.message.%s', $type));
        assertNotNull($message, sprintf('%s message not found.', $type));
    }

    /**
     * @Then /^the password for "([^"]*)" should be "([^"]*)"$/
     * @skipUpgrade
     * @param string $id
     * @param string $password
     */
    public function stepPasswordForEmailShouldBe($id, $password)
    {
        /** @var Member $member */
        $member = Member::get()->filter('Email', $id)->First();
        assertNotNull($member);
        assertTrue($member->checkPassword($password)->isValid());
    }

    /**
     * Get or generate a member with the given permission code
     *
     * @param string $email
     * @param string $password
     * @param string $permCode
     * @return Member
     */
    protected function generateMemberWithPermission($email, $password, $permCode)
    {
        // Get or create group
        $group = Group::get()->filter('Title', "$permCode group")->first();
        if (!$group) {
            $group = Group::create();
        }

        $group->Title = "$permCode group";
        $group->write();

        // Get or create permission
        $permission = Permission::create();
        $permission->Code = $permCode;
        $permission->write();
        $group->Permissions()->add($permission);

        // Get or create member
        $member = Member::get()->filter('Email', $email)->first();
        if (!$member) {
            $member = Member::create();
        }

        // make sure any validation for password is skipped, since we're not testing complexity here
        $validator = Member::password_validator();
        Member::set_password_validator(null);
        $member->FirstName = $permCode;
        $member->Surname = "User";
        $member->Email = $email;
        $member->PasswordEncryption = "none";
        $member->changePassword($password);
        $member->write();
        $group->Members()->add($member);
        Member::set_password_validator($validator);

        return $member;
    }
}
