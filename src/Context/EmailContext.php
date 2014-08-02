<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Session;
use SilverStripe\BehatExtension\Utility\TestMailer;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Context used to define steps related to email sending.
 */
class EmailContext implements Context
{
    use MainContextAwareTrait;

    /**
     * @var TestMailer
     */
    protected $mailer;

    /**
     * Stored to simplify later assertions
     */
    protected $lastMatchedEmail;

    /**
     * Get Mink session from MinkContext
     *
     * @param string $name
     * @return Session
     */
    public function getSession($name = null)
    {
        return $this->getMainContext()->getSession($name);
    }

    /**
     * @BeforeScenario
     * @param BeforeScenarioScope $event
     */
    public function before(BeforeScenarioScope $event)
    {
        // Also set through the 'supportbehat' extension
        // to ensure its available both in CLI execution and the tested browser session
        $this->mailer = new TestMailer();
        Injector::inst()->registerService($this->mailer, Mailer::class);
        Email::config()->update("send_all_emails_to", null);
    }

    /**
     * @Given /^there should (not |)be an email (to|from) "([^"]*)"$/
     * @param string $negate
     * @param string $direction
     * @param string $email
     */
    public function thereIsAnEmailFromTo($negate, $direction, $email)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from);
        if (trim($negate)) {
            assertNull($match);
        } else {
            assertNotNull($match);
        }
        $this->lastMatchedEmail = $match;
    }

    /**
     * @Given /^there should (not |)be an email (to|from) "([^"]*)" titled "([^"]*)"$/
     * @param string $negate
     * @param string $direction
     * @param string $email
     * @param string $subject
     */
    public function thereIsAnEmailFromToTitled($negate, $direction, $email, $subject)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from, $subject);
        $allMails = $this->mailer->findEmails($to, $from);
        $allTitles = $allMails ? '"' . implode('","', array_map(function ($email) {
            return $email->Subject;
        }, $allMails)) . '"' : null;
        if (trim($negate)) {
            assertNull($match);
        } else {
            $msg = sprintf(
                'Could not find email %s "%s" titled "%s".',
                $direction,
                $email,
                $subject
            );
            if ($allTitles) {
                $msg .= ' Existing emails: ' . $allTitles;
            }
            assertNotNull($match, $msg);
        }
        $this->lastMatchedEmail = $match;
    }

    /**
     * Example: Given the email should contain "Thank you for registering!".
     * Assumes an email has been identified by a previous step,
     * e.g. through 'Given there should be an email to "test@test.com"'.
     *
     * @Given /^the email should (not |)contain "([^"]*)"$/
     * @param string $negate
     * @param string $content
     */
    public function thereTheEmailContains($negate, $content)
    {
        if (!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $email = $this->lastMatchedEmail;
        $emailContent = null;
        if ($email->Content) {
            $emailContent = $email->Content;
        } else {
            $emailContent = $email->PlainContent;
        }

        if (trim($negate)) {
            assertNotContains($content, $emailContent);
        } else {
            assertContains($content, $emailContent);
        }
    }

    /**
     * Example: Given the email contains "Thank you for <strong>registering!<strong>".
     * Then the email should contain plain text "Thank you for registering!"
     * Assumes an email has been identified by a previous step,
     * e.g. through 'Given there should be an email to "test@test.com"'.
     *
     * @Given /^the email should contain plain text "([^"]*)"$/
     * @param string $content
     */
    public function thereTheEmailContainsPlainText($content)
    {
        if (!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $email = $this->lastMatchedEmail;
        $emailContent = ($email->Content) ? ($email->Content) : ($email->PlainContent);
        $emailPlainText = strip_tags($emailContent);
        $emailPlainText = preg_replace("/\h+/", " ", $emailPlainText);

        assertContains($content, $emailPlainText);
    }

    /**
     * @When /^I click on the "([^"]*)" link in the email (to|from) "([^"]*)"$/
     * @param string $linkSelector
     * @param string $direction
     * @param string $email
     */
    public function iGoToInTheEmailTo($linkSelector, $direction, $email)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from);
        assertNotNull($match);

        $crawler = new Crawler($match->Content);
        $linkEl = $crawler->selectLink($linkSelector);
        assertNotNull($linkEl);
        $link = $linkEl->attr('href');
        assertNotNull($link);

        $this->getMainContext()->visit($link);
    }

    /**
     * @When /^I click on the "([^"]*)" link in the email (to|from) "([^"]*)" titled "([^"]*)"$/
     * @param string $linkSelector
     * @param string $direction
     * @param string $email
     * @param string $title
     */
    public function iGoToInTheEmailToTitled($linkSelector, $direction, $email, $title)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from, $title);
        assertNotNull($match);

        $crawler = new Crawler($match->Content);
        $linkEl = $crawler->selectLink($linkSelector);
        assertNotNull($linkEl);
        $link = $linkEl->attr('href');
        assertNotNull($link);
        $this->getMainContext()->visit($link);
    }

    /**
     * Assumes an email has been identified by a previous step,
     * e.g. through 'Given there should be an email to "test@test.com"'.
     *
     * @When /^I click on the "([^"]*)" link in the email"$/
     * @param string $linkSelector
     */
    public function iGoToInTheEmail($linkSelector)
    {
        if (!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $match = $this->lastMatchedEmail;
        $crawler = new Crawler($match->Content);
        $linkEl = $crawler->selectLink($linkSelector);
        assertNotNull($linkEl);
        $link = $linkEl->attr('href');
        assertNotNull($link);

        $this->getMainContext()->visit($link);
    }

    /**
     * @Given /^I clear all emails$/
     */
    public function iClearAllEmails()
    {
        $this->lastMatchedEmail = null;
        $this->mailer->clearEmails();
    }

    /**
     * Example: Then the email should contain the following data:
     * | row1 |
     * | row2 |
     * Assumes an email has been identified by a previous step.
     * @Then /^the email should (not |)contain the following data:$/
     * @param string $negate
     * @param TableNode $table
     */
    public function theEmailContainFollowingData($negate, TableNode $table)
    {
        if (!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $email = $this->lastMatchedEmail;
        $emailContent = null;
        if ($email->Content) {
            $emailContent = $email->Content;
        } else {
            $emailContent = $email->PlainContent;
        }
        // Convert html content to plain text
        $emailContent = strip_tags($emailContent);
        $emailContent = preg_replace("/\h+/", " ", $emailContent);
        $rows = $table->getRows();

        // For "should not contain"
        if (trim($negate)) {
            foreach ($rows as $row) {
                assertNotContains($row[0], $emailContent);
            }
        } else {
            foreach ($rows as $row) {
                assertContains($row[0], $emailContent);
            }
        }
    }

    /**
     * @Then /^there should (not |)be an email titled "([^"]*)"$/
     * @param string $negate
     * @param string $subject
     */
    public function thereIsAnEmailTitled($negate, $subject)
    {
        $match = $this->mailer->findEmail(null, null, $subject);
        if (trim($negate)) {
            assertNull($match);
        } else {
            $msg = sprintf(
                'Could not find email titled "%s".',
                $subject
            );
            assertNotNull($match, $msg);
        }
        $this->lastMatchedEmail = $match;
    }

    /**
     * @Then /^the email should (not |)be sent from "([^"]*)"$/
     * @param string $negate
     * @param string $from
     */
    public function theEmailSentFrom($negate, $from)
    {
        if (!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $match = $this->lastMatchedEmail;
        if (trim($negate)) {
            assertNotContains($from, $match->From);
        } else {
            assertContains($from, $match->From);
        }
    }

    /**
     * @Then /^the email should (not |)be sent to "([^"]*)"$/
     * @param string $negate
     * @param string $to
     */
    public function theEmailSentTo($negate, $to)
    {
        if (!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $match = $this->lastMatchedEmail;
        if (trim($negate)) {
            assertNotContains($to, $match->To);
        } else {
            assertContains($to, $match->To);
        }
    }

    /**
     * The link text is the link address itself which contains special characters
     * e.g. http://localhost/Security/changepassword?m=199&title=reset
     * Example: When I click on the http link "changepassword" in the email
     * @When /^I click on the http link "([^"]*)" in the email$/
     * @param string $httpText
     */
    public function iClickOnHttpLinkInEmail($httpText)
    {
        if (!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $email = $this->lastMatchedEmail;
        $html = $email->Content;
        $dom = new \DOMDocument();
        $dom->loadHTML($html);

        $tags = $dom->getElementsByTagName('a');
        $href = null;
        foreach ($tags as $tag) {
            $linkText = $tag->nodeValue;
            if (strpos($linkText, $httpText) !== false) {
                $href = $linkText;
                break;
            }
        }
        assertNotNull($href);

        $this->getMainContext()->visit($href);
    }
}
