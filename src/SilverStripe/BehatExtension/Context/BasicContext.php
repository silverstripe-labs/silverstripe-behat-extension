<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\ClosuredContextInterface,
    Behat\Behat\Context\TranslatedContextInterface,
    Behat\Behat\Context\BehatContext,
    Behat\Behat\Context\Step,
    Behat\Behat\Event\StepEvent,
    Behat\Behat\Event\ScenarioEvent,
    Behat\Behat\Exception\PendingException;
use Behat\Mink\Driver\Selenium2Driver;
use Behat\Gherkin\Node\PyStringNode,
    Behat\Gherkin\Node\TableNode;

// PHPUnit
require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * BasicContext
 *
 * Context used to define generic steps like following anchors or pressing buttons.
 * Handles timeouts.
 * Handles redirections.
 * Handles AJAX enabled links, buttons and forms - jQuery is assumed.
 */
class BasicContext extends BehatContext
{
    protected $context;

    /**
	 * Date format in date() syntax
	 * @var String
	 */
	protected $dateFormat = 'Y-m-d';

	/**
	 * Time format in date() syntax
	 * @var String
	 */
	protected $timeFormat = 'H:i:s';

	/**
	 * Date/time format in date() syntax
	 * @var String
	 */
	protected $datetimeFormat = 'Y-m-d H:i:s';

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

    /**
     * Get Mink session from MinkContext
     */
    public function getSession($name = null)
    {
        return $this->getMainContext()->getSession($name);
    }

    /**
     * @AfterStep ~@modal
     *
     * Excluding scenarios with @modal tag is required,
     * because modal dialogs stop any JS interaction
     */
    public function appendErrorHandlerBeforeStep(StepEvent $event)
    {
        $javascript = <<<JS
window.onerror = function(msg) {
    var body = document.getElementsByTagName('body')[0];
    body.setAttribute('data-jserrors', '[captured JavaScript error] ' + msg);
}
if ('undefined' !== typeof window.jQuery) {
    window.jQuery('body').ajaxError(function(event, jqxhr, settings, exception) {
        if ('abort' === exception) return;
        window.onerror(event.type + ': ' + settings.type + ' ' + settings.url + ' ' + exception + ' ' + jqxhr.responseText);
    });
}
JS;

        $this->getSession()->executeScript($javascript);
    }

    /**
     * @AfterStep ~@modal
     *
     * Excluding scenarios with @modal tag is required,
     * because modal dialogs stop any JS interaction
     */
    public function readErrorHandlerAfterStep(StepEvent $event)
    {
        $page = $this->getSession()->getPage();

        $jserrors = $page->find('xpath', '//body[@data-jserrors]');
        if (null !== $jserrors) {
            $this->takeScreenshot($event);
            file_put_contents('php://stderr', $jserrors->getAttribute('data-jserrors') . PHP_EOL);
        }

        $javascript = <<<JS
(function() {
    var body = document.getElementsByTagName('body')[0];
    body.removeAttribute('data-jserrors');
})();
JS;

        $this->getSession()->executeScript($javascript);
    }

    /**
     * Hook into jQuery ajaxStart, ajaxSuccess and ajaxComplete events.
     * Prepare __ajaxStatus() functions and attach them to these handlers.
     * Event handlers are removed after one run.
     *
     * @BeforeStep
     */
    public function handleAjaxBeforeStep(StepEvent $event)
    {
        $ajaxEnabledSteps = $this->getMainContext()->getAjaxSteps();
        $ajaxEnabledSteps = implode('|', array_filter($ajaxEnabledSteps));

        if (empty($ajaxEnabledSteps) || !preg_match('/(' . $ajaxEnabledSteps . ')/i', $event->getStep()->getText())) {
            return;
        }

        $javascript = <<<JS
if ('undefined' !== typeof window.jQuery) {
    window.jQuery(document).on('ajaxStart.ss.test.behaviour', function(){
        window.__ajaxStatus = function() {
            return 'waiting';
        };
    });
    window.jQuery(document).on('ajaxComplete.ss.test.behaviour', function(e, jqXHR){
        if (null === jqXHR.getResponseHeader('X-ControllerURL')) {
            window.__ajaxStatus = function() {
                return 'no ajax';
            };
        }
    });
    window.jQuery(document).on('ajaxSuccess.ss.test.behaviour', function(e, jqXHR){
        if (null === jqXHR.getResponseHeader('X-ControllerURL')) {
            window.__ajaxStatus = function() {
                return 'success';
            };
        }
    });
}
JS;
        $this->getSession()->wait(500); // give browser a chance to process and render response
        $this->getSession()->executeScript($javascript);
    }

    /**
     * Wait for the __ajaxStatus()to return anything but 'waiting'.
     * Don't wait longer than 5 seconds.
     *
     * Don't unregister handler if we're dealing with modal windows
     *
     * @AfterStep ~@modal
     */
    public function handleAjaxAfterStep(StepEvent $event)
    {
        $ajaxEnabledSteps = $this->getMainContext()->getAjaxSteps();
        $ajaxEnabledSteps = implode('|', array_filter($ajaxEnabledSteps));

        if (empty($ajaxEnabledSteps) || !preg_match('/(' . $ajaxEnabledSteps . ')/i', $event->getStep()->getText())) {
            return;
        }

        $this->handleAjaxTimeout();

        $javascript = <<<JS
if ('undefined' !== typeof window.jQuery) {
window.jQuery(document).off('ajaxStart.ss.test.behaviour');
window.jQuery(document).off('ajaxComplete.ss.test.behaviour');
window.jQuery(document).off('ajaxSuccess.ss.test.behaviour');
}
JS;
        $this->getSession()->executeScript($javascript);
    }

    public function handleAjaxTimeout()
    {
        $timeoutMs = $this->getMainContext()->getAjaxTimeout();

        // Wait for an ajax request to complete, but only for a maximum of 5 seconds to avoid deadlocks
        $this->getSession()->wait($timeoutMs,
            "(typeof window.__ajaxStatus !== 'undefined' ? window.__ajaxStatus() : 'no ajax') !== 'waiting'"
        );

        // wait additional 100ms to allow DOM to update
        $this->getSession()->wait(100);
    }

    /**
     * Take screenshot when step fails.
     * Works only with Selenium2Driver.
     *
     * @AfterStep
     */
    public function takeScreenshotAfterFailedStep(StepEvent $event)
    {
        if (4 === $event->getResult()) {
            $this->takeScreenshot($event);
        }
    }

    /**
     * Delete any created files and folders from assets directory
     * 
     * @AfterScenario @assets
     */
    public function cleanAssetsAfterScenario(ScenarioEvent $event)
    {
        foreach(\File::get() as $file) {
            if(file_exists($file->getFullPath())) $file->delete();
        }
    }

    public function takeScreenshot(StepEvent $event) {
        $driver = $this->getSession()->getDriver();
        // quit silently when unsupported
        if (!($driver instanceof Selenium2Driver)) {
            return;
        }

        $parent = $event->getLogicalParent();
        $feature = $parent->getFeature();
        $step = $event->getStep();
        $screenshotPath = null;

        $path = $this->getMainContext()->getScreenshotPath();
        if(!$path) return; // quit silently when path is not set

        \Filesystem::makeFolder($path);
        $path = realpath($path);
        
        if (!file_exists($path)) {
            file_put_contents('php://stderr', sprintf('"%s" is not valid directory and failed to create it' . PHP_EOL, $path));
            return;
        }

        if (file_exists($path) && !is_dir($path)) {
            file_put_contents('php://stderr', sprintf('"%s" is not valid directory' . PHP_EOL, $path));
            return;
        }
        if (file_exists($path) && !is_writable($path)) {
            file_put_contents('php://stderr', sprintf('"%s" directory is not writable' . PHP_EOL, $path));
            return;
        }

        $path = sprintf('%s/%s_%d.png', $path, basename($feature->getFile()), $step->getLine());
        $screenshot = $driver->getWebDriverSession()->screenshot();
        file_put_contents($path, base64_decode($screenshot));
        file_put_contents('php://stderr', sprintf('Saving screenshot into %s' . PHP_EOL, $path));
    }

    /**
     * @Then /^I should be redirected to "([^"]+)"/
     */
    public function stepIShouldBeRedirectedTo($url)
    {
        if ($this->getMainContext()->canIntercept()) {
            $client = $this->getSession()->getDriver()->getClient();
            $client->followRedirects(true);
            $client->followRedirect();

            $url = $this->getMainContext()->joinUrlParts($this->context['base_url'], $url);

            assertTrue($this->getMainContext()->isCurrentUrlSimilarTo($url), sprintf('Current URL is not %s', $url));
        }
    }

    /**
     * @Given /^the page can't be found/
     */
    public function stepPageCantBeFound() 
    {
        $page = $this->getSession()->getPage();
        assertTrue(
            // Content from ErrorPage default record
            $page->hasContent('Page not found')
            // Generic ModelAsController message
            || $page->hasContent('The requested page could not be found')
        );
    }

    /**
     * @Given /^I wait (?:for )?([\d\.]+) second(?:s?)$/
     */
    public function stepIWaitFor($secs)
    {
        $this->getSession()->wait((float)$secs*1000);
    }

    /**
     * @Given /^I press the "([^"]*)" button$/
     */
    public function stepIPressTheButton($button)
    {
        $page = $this->getSession()->getPage();
        $els = $page->findAll('named', array('link_or_button', "'$button'"));
        $matchedEl = null;
        foreach($els as $el) {
            if($el->isVisible()) $matchedEl = $el;
        }
        assertNotNull($matchedEl, sprintf('%s button not found', $button));
        $matchedEl->click();
    }

    /**
     * Needs to be in single command to avoid "unexpected alert open" errors in Selenium.
     * 
     * @Given /^I press the "([^"]*)" button, confirming the dialog$/
     */
    public function stepIPressTheButtonConfirmingTheDialog($button)
    {
        $this->stepIPressTheButton($button);
        $this->iConfirmTheDialog();
    }

    /**
     * @Given /^I click "([^"]*)" in the "([^"]*)" element$/
     */
    public function iClickInTheElement($text, $selector)
    {
        $page = $this->getSession()->getPage();

        $parentElement = $page->find('css', $selector);
        assertNotNull($parentElement, sprintf('"%s" element not found', $selector));

        $element = $parentElement->find('xpath', sprintf('//*[count(*)=0 and contains(.,"%s")]', $text));
        assertNotNull($element, sprintf('"%s" not found', $text));

        $element->click();
    }

    /**
     * @Given /^I type "([^"]*)" into the dialog$/
     */
    public function iTypeIntoTheDialog($data)
    {
        $data = array(
            'text' => $data,
        );
        $this->getSession()->getDriver()->getWebDriverSession()->postAlert_text($data);
    }

    /**
     * @Given /^I confirm the dialog$/
     */
    public function iConfirmTheDialog()
    {
        $this->getSession()->getDriver()->getWebDriverSession()->accept_alert();
        $this->handleAjaxTimeout();
    }

    /**
     * @Given /^I dismiss the dialog$/
     */
    public function iDismissTheDialog()
    {
        $this->getSession()->getDriver()->getWebDriverSession()->dismiss_alert();
        $this->handleAjaxTimeout();
    }

    /**
     * @Given /^(?:|I )attach the file "(?P<path>[^"]*)" to "(?P<field>(?:[^"]|\\")*)" with HTML5$/
     */
    public function iAttachTheFileTo($field, $path)
    {
        // Remove wrapped button styling to make input field accessible to Selenium
        $js = <<<JS
var input = jQuery('[name="$field"]');
if(input.closest('.ss-uploadfield-item-info').length) {
    while(!input.parent().is('.ss-uploadfield-item-info')) input = input.unwrap();
}
JS;
        $this->getSession()->evaluateScript($js);
        $this->getSession()->wait(1000);

        return new Step\Given(sprintf('I attach the file "%s" to "%s"', $path, $field));
    }

	/**
	 * Select an individual input from within a group, matched by the top-most label.
	 *
	 * @Given /^I select "([^"]*)" from "([^"]*)" input group$/
	 */
	public function iSelectFromInputGroup($value, $labelText) {
		$page = $this->getSession()->getPage();
		$parent = null;

		foreach($page->findAll('css', 'label') as $label) {
			if($label->getText() == $labelText) {
				$parent = $label->getParent();
			}
		}

		if(!$parent) throw new \InvalidArgumentException(sprintf('Input group with label "%s" cannot be found', $labelText));

		foreach($parent->findAll('css', 'label') as $option) {
			if($option->getText() == $value) {
				$for = $option->getAttribute('for');
				$input = $parent->findById($for);

				if(!$input) throw new \InvalidArgumentException(sprintf('Input "%s" cannot be found', $value));

				$this->getSession()->getDriver()->click($input->getXPath());
			}
		}
	}

	/**
	 * Finds a named <table> element. See {@link \Behat\Mink\Selector\NamedSelector} for ways to specify how to find
	 * the table. The easiest of these is adding a <caption> element to the table in question.
	 *
	 * @Then /^I should see "([^"]*)" in the "([^"]*)" table$/
	 */
	public function iShouldSeeInTheTable($value, $tableName) {
		// Get table
		$page = $this->getSession()->getPage();
		$table = $page->find('named', array('table', "$tableName"));

		if($table) {
			assertContains($value, $table->getText(), "Couldn't find '$value' in the '$tableName' table");
		} else {
			throw new \InvalidArgumentException("Couldn't find a table called '$tableName'");
		}
	}

	/**
	 * Transforms relative time statements compatible with strtotime().
	 * Example: "time of 1 hour ago" might return "22:00:00" if its currently "23:00:00".
	 * Customize through {@link setTimeFormat()}.
	 * 
	 * @Transform /^(?:(the|a)) time of (?<val>.*)$/
	 */
	public function castRelativeToAbsoluteTime($prefix, $val) {
		$timestamp = strtotime($val);
		if(!$timestamp) {
			throw new \InvalidArgumentException(sprintf(
				"Can't resolve '%s' into a valid datetime value",
				$val
			));
		}
		return date($this->timeFormat, $timestamp);
	}

	/**
	 * Transforms relative date and time statements compatible with strtotime().
	 * Example: "datetime of 2 days ago" might return "2013-10-10 22:00:00" if its currently 
	 * the 12th of October 2013. Customize through {@link setDatetimeFormat()}.
	 * 
	 * @Transform /^(?:(the|a)) datetime of (?<val>.*)$/
	 */
	public function castRelativeToAbsoluteDatetime($prefix, $val) {
		$timestamp = strtotime($val);
		if(!$timestamp) {
			throw new \InvalidArgumentException(sprintf(
				"Can't resolve '%s' into a valid datetime value",
				$val
			));
		}
		return date($this->datetimeFormat, $timestamp);
	}

	/**
	 * Transforms relative date statements compatible with strtotime().
	 * Example: "date 2 days ago" might return "2013-10-10" if its currently 
	 * the 12th of October 2013. Customize through {@link setDateFormat()}.
	 * 
	 * @Transform /^(?:(the|a)) date of (?<val>.*)$/
	 */
	public function castRelativeToAbsoluteDate($prefix, $val) {
		$timestamp = strtotime($val);
		if(!$timestamp) {
			throw new \InvalidArgumentException(sprintf(
				"Can't resolve '%s' into a valid datetime value",
				$val
			));
		}
		return date($this->dateFormat, $timestamp);
	}

	public function getDateFormat() {
		return $this->dateFormat;
	}

	public function setDateFormat($format) {
		$this->dateFormat = $format;
	}

	public function getTimeFormat() {
		return $this->timeFormat;
	}

	public function setTimeFormat($format) {
		$this->timeFormat = $format;
	}

	public function getDatetimeFormat() {
		return $this->datetimeFormat;
	}

	public function setDatetimeFormat($format) {
		$this->datetimeFormat = $format;
	}
}
