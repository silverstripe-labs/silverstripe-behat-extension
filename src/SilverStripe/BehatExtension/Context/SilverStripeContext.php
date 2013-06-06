<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\Step,
    Behat\Behat\Event\FeatureEvent,
    Behat\Behat\Event\ScenarioEvent,
    Behat\Behat\Event\SuiteEvent;
use Behat\Gherkin\Node\PyStringNode;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Mink\Driver\GoutteDriver,
    Behat\Mink\Driver\Selenium2Driver,
    Behat\Mink\Exception\UnsupportedDriverActionException;

use SilverStripe\BehatExtension\Context\SilverStripeAwareContextInterface;

use Symfony\Component\Yaml\Yaml;

// Mink etc.
require_once 'vendor/autoload.php';

/**
 * SilverStripeContext
 *
 * Generic context wrapper used as a base for Behat FeatureContext.
 */
class SilverStripeContext extends MinkContext implements SilverStripeAwareContextInterface
{
    protected $databaseName;

    /**
     * @var Array Partial string match for step names
     * that are considered to trigger Ajax request in the CMS,
     * and hence need special timeout handling.
     * @see \SilverStripe\BehatExtension\Context\BasicContext->handleAjaxBeforeStep().
     */
    protected $ajaxSteps;

    /**
     * @var Int Timeout in milliseconds, after which the interface assumes
     * that an Ajax request has timed out, and continues with assertions.
     */
    protected $ajaxTimeout;

    /**
     * @var String Relative URL to the SilverStripe admin interface.
     */
    protected $adminUrl;

    /**
     * @var String Relative URL to the SilverStripe login form.
     */
    protected $loginUrl;

    /**
     * @var String Relative path to a writeable folder where screenshots can be stored.
     * If set to NULL, no screenshots will be stored.
     */
    protected $screenshotPath;

    protected $context;
    

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param   array   $parameters     context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters)
    {
        // Initialize your context here
        $this->context = $parameters;
    }

    public function setDatabase($databaseName)
    {
        $this->databaseName = $databaseName;
    }

    public function setAjaxSteps($ajaxSteps)
    {
        if($ajaxSteps) $this->ajaxSteps = $ajaxSteps;
    }

    public function getAjaxSteps()
    {
        return $this->ajaxSteps;
    }

    public function setAjaxTimeout($ajaxTimeout)
    {
        $this->ajaxTimeout = $ajaxTimeout;
    }

    public function getAjaxTimeout()
    {
        return $this->ajaxTimeout;
    }

    public function setAdminUrl($adminUrl)
    {
        $this->adminUrl = $adminUrl;
    }

    public function getAdminUrl()
    {
        return $this->adminUrl;
    }

    public function setLoginUrl($loginUrl)
    {
        $this->loginUrl = $loginUrl;
    }

    public function getLoginUrl()
    {
        return $this->loginUrl;
    }

    public function setScreenshotPath($screenshotPath)
    {
        $this->screenshotPath = $screenshotPath;
    }

    public function getScreenshotPath()
    {
        return $this->screenshotPath;
    }

    /**
     * @BeforeScenario
     */
    public function before(ScenarioEvent $event)
    {
        if (!isset($this->databaseName)) {
            throw new \LogicException(
                'Context\'s $databaseName has to be set when implementing '
                . 'SilverStripeAwareContextInterface.'
            );
        }

        $url = $this->joinUrlParts($this->getBaseUrl(), '/dev/testsession/start');
        $params = array(
            'database' => $this->databaseName,
            'mailer' => 'SilverStripe\BehatExtension\Utility\TestMailer',
        );
        $url .= '?' . http_build_query($params);

        $this->getSession()->visit($url);

        $page = $this->getSession()->getPage();
        $content = $page->getContent();

        if(!preg_match('/<!-- +SUCCESS: +DBNAME=([^ ]+)/i', $content, $matches)) {
            throw new \LogicException("Could not create a test session.  Details below:\n" . $content);

        } else if($matches[1] != $this->databaseName) {
            throw new \LogicException("Test session is using the database $matches[1]; it should be using $this->databaseName.");

        }

        $loginForm = $page->find('css', '#MemberLoginForm_LoginForm');

    }

    /**
     * Parses given URL and returns its components
     *
     * @param $url
     * @return array|mixed Parsed URL
     */
    public function parseUrl($url)
    {
        $url = parse_url($url);
        $url['vars'] = array();
        if (!isset($url['fragment'])) {
            $url['fragment'] = null;
        }
        if (isset($url['query'])) {
            parse_str($url['query'], $url['vars']);
        }

        return $url;
    }

    /**
     * Checks whether current URL is close enough to the given URL.
     * Unless specified in $url, get vars will be ignored
     * Unless specified in $url, fragment identifiers will be ignored
     *
     * @param $url string URL to compare to current URL
     * @return boolean Returns true if the current URL is close enough to the given URL, false otherwise.
     */
    public function isCurrentUrlSimilarTo($url)
    {
        $current = $this->parseUrl($this->getSession()->getCurrentUrl());
        $test = $this->parseUrl($url);

        if ($current['path'] !== $test['path']) {
            return false;
        }

        if (isset($test['fragment']) && $current['fragment'] !== $test['fragment']) {
            return false;
        }

        foreach ($test['vars'] as $name => $value) {
            if (!isset($current['vars'][$name]) || $current['vars'][$name] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns base URL parameter set in MinkExtension.
     * It simplifies configuration by allowing to specify this parameter
     * once but makes code dependent on MinkExtension.
     *
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->getMinkParameter('base_url') ?: '';
    }

    /**
     * Joins URL parts into an URL using forward slash.
     * Forward slash usages are normalised to one between parts.
     * This method takes variable number of parameters.
     *
     * @param $...
     * @return string
     * @throws \InvalidArgumentException
     */
    public function joinUrlParts()
    {
        if (0 === func_num_args()) {
            throw new \InvalidArgumentException('Need at least one argument');
        }

        $parts = func_get_args();
        $trimSlashes = function(&$part) {
            $part = trim($part, '/');
        };
        array_walk($parts, $trimSlashes);

        return implode('/', $parts);
    }

    public function canIntercept()
    {
        $driver = $this->getSession()->getDriver();
        if ($driver instanceof GoutteDriver) {
            return true;
        }
        else {
            if ($driver instanceof Selenium2Driver) {
                return false;
            }
        }

        throw new UnsupportedDriverActionException('You need to tag the scenario with "@mink:goutte" or "@mink:symfony". Intercepting the redirections is not supported by %s', $driver);
    }

    /**
     * @Given /^(.*) without redirection$/
     */
    public function theRedirectionsAreIntercepted($step)
    {
        if ($this->canIntercept()) {
            $this->getSession()->getDriver()->getClient()->followRedirects(false);
        }

        return new Step\Given($step);
    }

}
