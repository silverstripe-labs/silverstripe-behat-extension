<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\ClosuredContextInterface,
Behat\Behat\Context\TranslatedContextInterface,
Behat\Behat\Context\BehatContext,
Behat\Behat\Context\Step,
Behat\Behat\Event\FeatureEvent,
Behat\Behat\Event\ScenarioEvent,
Behat\Behat\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode,
Behat\Gherkin\Node\TableNode;
use Symfony\Component\DomCrawler\Crawler;

// PHPUnit
require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * Context used to define steps related to email sending.
 */
class EmailContext extends BehatContext
{
    protected $context;

    protected $mailer;

    /**
     * Stored to simplify later assertions
     */
    protected $lastMatchedEmail;

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
     * @BeforeScenario
     */
    public function before(ScenarioEvent $event)
    {
        // Also set through the 'supportbehat' extension
        // to ensure its available both in CLI execution and the tested browser session
        $this->mailer = new \SilverStripe\BehatExtension\Utility\TestMailer();
        \Email::set_mailer($this->mailer);
        \Config::inst()->update("Email","send_all_emails_to", null);
    }

    /**
     * @Given /^there should (not |)be an email (to|from) "([^"]*)"$/
     */
    public function thereIsAnEmailFromTo($negate, $direction, $email)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from);
        if(trim($negate)) {
            assertNull($match);
        } else {
            assertNotNull($match);
        }
        $this->lastMatchedEmail = $match;
    }

    /**
     * @Given /^there should (not |)be an email (to|from) "([^"]*)" titled "([^"]*)"$/
     */
    public function thereIsAnEmailFromToTitled($negate, $direction, $email, $subject)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from, $subject);
        $allMails = $this->mailer->findEmails($to, $from);
        $allTitles = $allMails ? '"' . implode('","', array_map(function($email) {return $email->Subject;}, $allMails)) . '"' : null;
        if(trim($negate)) {
            assertNull($match);
        } else {
            $msg = sprintf(
                'Could not find email %s "%s" titled "%s".',
                $direction,
                $email,
                $subject
            );
            if($allTitles) {
                $msg .= ' Existing emails: ' . $allTitles;
            }
            assertNotNull($match,$msg);
        }
        $this->lastMatchedEmail = $match;
    }

    /**
     * Example: Given the email contains "Thank you for registering!".
     * Assumes an email has been identified by a previous step,
     * e.g. through 'Given there should be an email to "test@test.com"'.
     * 
     * @Given /^the email should contain "([^"]*)"$/
     */
    public function thereTheEmailContains($content)
    {
        if(!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $email = $this->lastMatchedEmail;
        if($email->Content) {
            assertContains($content, $this->plaintext($email->Content));
        } else {
            assertContains($content, $email->PlainContent);
        }
    }

    /* $email->Content returns all the styles and scripts, this helper method
     * strips all the scripts and tags to help debugging styled emails 
     * @param  [type] $html [description]
     * @return [type]       [description]
     * */
    public function plaintext($html) {
        // remove comments and any content found in the the comment area (strip_tags only removes the actual tags).
        $plaintext = preg_replace('#<!--.*?-->#s', '', $html);

        // put a space between list items (strip_tags just removes the tags).
        $plaintext = preg_replace('#</li>#', ' </li>', $plaintext);

        // remove all script and style tags
        $plaintext = preg_replace('#<(script|style)\b[^>]*>(.*?)</(script|style)>#is', "", $plaintext);

        // remove br tags (missed by strip_tags)
        $plaintext = preg_replace("#<br[^>]*?>#", " ", $plaintext);

        // remove all remaining html
        $plaintext = strip_tags($plaintext);

        // parse whitespace/spaces/linebreaks into a single line
        $plaintext = preg_replace("/\s+/", ' ', $plaintext); 

        return $plaintext;
    }   

    /**
     * @When /^I click on the "([^"]*)" link in the email (to|from) "([^"]*)"$/
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
        
        return new Step\When(sprintf('I go to "%s"', $link));
    }

    /**
     * @When /^I click on the "([^"]*)" link in the email (to|from) "([^"]*)" titled "([^"]*)"$/
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
        return new Step\When(sprintf('I go to "%s"', $link));
    }
    
    /**
     * Assumes an email has been identified by a previous step,
     * e.g. through 'Given there should be an email to "test@test.com"'.
     * 
     * @When /^I click on the "([^"]*)" link in the email"$/
     */
    public function iGoToInTheEmail($linkSelector)
    {
        if(!$this->lastMatchedEmail) {
            throw new \LogicException('No matched email found from previous step');
        }

        $match = $this->lastMatchedEmail;
        $crawler = new Crawler($match->Content);
        $linkEl = $crawler->selectLink($linkSelector);
        assertNotNull($linkEl);
        $link = $linkEl->attr('href');
        assertNotNull($link);

        return new Step\When(sprintf('I go to "%s"', $link));
    }

    /**
     * @Given /^I clear all emails$/
     */
    public function iClearAllEmails()
    {
        $this->lastMatchedEmail = null;
        return $this->mailer->clearEmails();
    }
}
