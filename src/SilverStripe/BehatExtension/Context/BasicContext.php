<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\BehatContext,
    Behat\Behat\Context\Step,
    Behat\Behat\Event\StepEvent,
    Behat\Behat\Event\ScenarioEvent;

use Behat\Mink\Driver\Selenium2Driver;

use Behat\Gherkin\Node\PyStringNode,
    Behat\Gherkin\Node\TableNode;

// PHPUnit
require_once BASE_PATH . '/vendor/phpunit/phpunit/src/Framework/Assert/Functions.php';

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
	public function __construct(array $parameters) {
        // Initialize your context here
        $this->context = $parameters;
    }

	/**
	 * Get Mink session from MinkContext
	 *
	 * @return \Behat\Mink\Session
	 */
	public function getSession($name = null) {
		return $this->getMainContext()->getSession($name);
	}

    /**
     * @AfterStep ~@modal
     *
     * Excluding scenarios with @modal tag is required,
     * because modal dialogs stop any JS interaction
     */
	public function appendErrorHandlerBeforeStep(StepEvent $event) {
		try{
			$javascript = <<<JS
window.onerror = function(message, file, line, column, error) {
    var body = document.getElementsByTagName('body')[0];
	var msg = message + " in " + file + ":" + line + ":" + column;
	if(error !== undefined && error.stack !== undefined) {
		msg += "\\nSTACKTRACE:\\n" + error.stack;
	}
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
        }catch(\WebDriver\Exception $e){
        	$this->logException($e);
        }
    }

    /**
     * @AfterStep ~@modal
     *
     * Excluding scenarios with @modal tag is required,
     * because modal dialogs stop any JS interaction
     */
	public function readErrorHandlerAfterStep(StepEvent $event) {
		try{
	        $page = $this->getSession()->getPage();
	
	        $jserrors = $page->find('xpath', '//body[@data-jserrors]');
	        if (null !== $jserrors) {
	            $this->takeScreenshot($event);
	            file_put_contents('php://stderr', $jserrors->getAttribute('data-jserrors') . PHP_EOL);
	        }
	
	        $javascript = <<<JS
if ('undefined' !== typeof window.jQuery) {
	window.jQuery(document).ready(function() {
		window.jQuery('body').removeAttr('data-jserrors');
	});
}
JS;

			$this->getSession()->executeScript($javascript);
		}catch(\WebDriver\Exception $e){
			$this->logException($e);
        }
    }

    /**
     * Hook into jQuery ajaxStart, ajaxSuccess and ajaxComplete events.
     * Prepare __ajaxStatus() functions and attach them to these handlers.
     * Event handlers are removed after one run.
     *
     * @BeforeStep
     */
	public function handleAjaxBeforeStep(StepEvent $event) {
		try{
			$ajaxEnabledSteps = $this->getMainContext()->getAjaxSteps();
			$ajaxEnabledSteps = implode('|', array_filter($ajaxEnabledSteps));

			if (empty($ajaxEnabledSteps) || !preg_match('/(' . $ajaxEnabledSteps . ')/i', $event->getStep()->getText())) {
				return;
			}

			$javascript = <<<JS
if ('undefined' !== typeof window.jQuery && 'undefined' !== typeof window.jQuery.fn.on) {
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
		}catch(\WebDriver\Exception $e){
			$this->logException($e);
        }
    }

    /**
     * Wait for the __ajaxStatus()to return anything but 'waiting'.
     * Don't wait longer than 5 seconds.
     *
     * Don't unregister handler if we're dealing with modal windows
     *
     * @AfterStep ~@modal
     */
	public function handleAjaxAfterStep(StepEvent $event) {
		try{
			$ajaxEnabledSteps = $this->getMainContext()->getAjaxSteps();
			$ajaxEnabledSteps = implode('|', array_filter($ajaxEnabledSteps));

			if (empty($ajaxEnabledSteps) || !preg_match('/(' . $ajaxEnabledSteps . ')/i', $event->getStep()->getText())) {
				return;
			}

			$this->handleAjaxTimeout();

			$javascript = <<<JS
if ('undefined' !== typeof window.jQuery && 'undefined' !== typeof window.jQuery.fn.off) {
window.jQuery(document).off('ajaxStart.ss.test.behaviour');
window.jQuery(document).off('ajaxComplete.ss.test.behaviour');
window.jQuery(document).off('ajaxSuccess.ss.test.behaviour');
}
JS;
			$this->getSession()->executeScript($javascript);
		}catch(\WebDriver\Exception $e){
			$this->logException($e);
        }
    }

	public function handleAjaxTimeout() {
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
	public function takeScreenshotAfterFailedStep(StepEvent $event) {
		if (4 === $event->getResult()) {
			try{
				$this->takeScreenshot($event);
			}catch(\WebDriver\Exception $e){
				$this->logException($e);
			}
		}
	}

	/**
	 * Close modal dialog if test scenario fails on CMS page
	 *
	 * @AfterScenario
	 */
	public function closeModalDialog(ScenarioEvent $event) {
		try{
			// Only for failed tests on CMS page
			if (4 === $event->getResult()) {
				$cmsElement = $this->getSession()->getPage()->find('css', '.cms');
				if($cmsElement) {
					try {
						// Navigate away triggered by reloading the page
						$this->getSession()->reload();
						$this->getSession()->getDriver()->getWebDriverSession()->accept_alert();
					} catch(\WebDriver\Exception $e) {
						// no-op, alert might not be present
					}
				}
			}
		}catch(\WebDriver\Exception $e){
			$this->logException($e);
		}
	}
	
    /**
     * Delete any created files and folders from assets directory
     *
     * @AfterScenario @assets
     */
	public function cleanAssetsAfterScenario(ScenarioEvent $event) {
        foreach(\File::get() as $file) {
            $file->delete();
        }
		\Filesystem::removeFolder(ASSETS_PATH, true);
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
	public function stepIShouldBeRedirectedTo($url) {
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
	public function stepPageCantBeFound() {
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
	public function stepIWaitFor($secs) {
        $this->getSession()->wait((float)$secs*1000);
    }

    /**
     * @Given /^I press the "([^"]*)" button$/
     */
	public function stepIPressTheButton($button) {
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
     * Example1: I press the "Remove current combo" button, confirming the dialog
     * Example2: I follow the "Remove current combo" link, confirming the dialog
     *
     * @Given /^I (?:press|follow) the "([^"]*)" (?:button|link), confirming the dialog$/
     */
	public function stepIPressTheButtonConfirmingTheDialog($button) {
        $this->stepIPressTheButton($button);
        $this->iConfirmTheDialog();
    }

    /**
     * Needs to be in single command to avoid "unexpected alert open" errors in Selenium.
     * Example: I follow the "Remove current combo" link, dismissing the dialog
     *
     * @Given /^I (?:press|follow) the "([^"]*)" (?:button|link), dismissing the dialog$/
     */
	public function stepIPressTheButtonDismissingTheDialog($button) {
		$this->stepIPressTheButton($button);
		$this->iDismissTheDialog();
    }

    /**
     * @Given /^I (click|double click) "([^"]*)" in the "([^"]*)" element$/
     */
    public function iClickInTheElement($clickType, $text, $selector) {
        $clickTypeMap = array(
            "double click" => "doubleclick",
            "click" => "click"
        );
        $page = $this->getSession()->getPage();
        $parentElement = $page->find('css', $selector);
        assertNotNull($parentElement, sprintf('"%s" element not found', $selector));
        $element = $parentElement->find('xpath', sprintf('//*[count(*)=0 and contains(.,"%s")]', $text));
        assertNotNull($element, sprintf('"%s" not found', $text));
        $clickTypeFn = $clickTypeMap[$clickType];
        $element->$clickTypeFn();
    }
    
    /**
    * Needs to be in single command to avoid "unexpected alert open" errors in Selenium.
    * Example: I click "Delete" in the ".actions" element, confirming the dialog
    *
    * @Given /^I (click|double click) "([^"]*)" in the "([^"]*)" element, confirming the dialog$/
    */
   public function iClickInTheElementConfirmingTheDialog($clickType, $text, $selector) {
       $this->iClickInTheElement($clickType, $text, $selector);
       $this->iConfirmTheDialog();
   }
   /**
    * Needs to be in single command to avoid "unexpected alert open" errors in Selenium.
    * Example: I click "Delete" in the ".actions" element, dismissing the dialog
    *
    * @Given /^I (click|double click) "([^"]*)" in the "([^"]*)" element, dismissing the dialog$/
    */
   public function iClickInTheElementDismissingTheDialog($clickType, $text, $selector) {
       $this->iClickInTheElement($clickType, $text, $selector);
       $this->iDismissTheDialog();
   }

    /**
     * @Given /^I type "([^"]*)" into the dialog$/
     */
	public function iTypeIntoTheDialog($data) {
        $data = array(
            'text' => $data,
        );
        $this->getSession()->getDriver()->getWebDriverSession()->postAlert_text($data);
    }

    /**
     * @Given /^I confirm the dialog$/
     */
	public function iConfirmTheDialog() {
        $this->getSession()->getDriver()->getWebDriverSession()->accept_alert();
        $this->handleAjaxTimeout();
    }

    /**
     * @Given /^I dismiss the dialog$/
     */
	public function iDismissTheDialog() {
        $this->getSession()->getDriver()->getWebDriverSession()->dismiss_alert();
        $this->handleAjaxTimeout();
    }

    /**
     * @Given /^(?:|I )attach the file "(?P<path>[^"]*)" to "(?P<field>(?:[^"]|\\")*)" with HTML5$/
     */
	public function iAttachTheFileTo($field, $path) {
        // Remove wrapped button styling to make input field accessible to Selenium
        $js = <<<JS
var input = jQuery('[name="$field"]');
if(input.closest('.ss-uploadfield-item-info').length) {
    while(!input.parent().is('.ss-uploadfield-item-info')) input = input.unwrap();
}
JS;

        $this->getSession()->executeScript($js);
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
     * Pauses the scenario until the user presses a key. Useful when debugging a scenario.
     *
     * @Then /^(?:|I )put a breakpoint$/
     */
	public function iPutABreakpoint() {
        fwrite(STDOUT, "\033[s    \033[93m[Breakpoint] Press \033[1;93m[RETURN]\033[0;93m to continue...\033[0m");
        while (fgets(STDIN, 1024) == '') {}
        fwrite(STDOUT, "\033[u");

        return;
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

    /**
     * Checks that field with specified in|name|label|value is disabled.
     * Example: Then the field "Email" should be disabled
     * Example: Then the "Email" field should be disabled
     *
     * @Then /^the "(?P<name>(?:[^"]|\\")*)" (?P<type>(?:(field|button))) should (?P<negate>(?:(not |)))be disabled/
     * @Then /^the (?P<type>(?:(field|button))) "(?P<name>(?:[^"]|\\")*)" should (?P<negate>(?:(not |)))be disabled/
     */
    public function stepFieldShouldBeDisabled($name, $type, $negate) {
        $page = $this->getSession()->getPage();
        if($type == 'field') {
            $element = $page->findField($name);
        } else {
            $element = $page->find('named', array(
                'button', $this->getSession()->getSelectorsHandler()->xpathLiteral($name)
            ));
        }

        assertNotNull($element, sprintf("Element '%s' not found", $name));

        $disabledAttribute = $element->getAttribute('disabled');
        if(trim($negate)) {
            assertNull($disabledAttribute, sprintf("Failed asserting element '%s' is not disabled", $name));
        } else {
            assertNotNull($disabledAttribute, sprintf("Failed asserting element '%s' is disabled", $name));
        }
    }

	/**
	 * Checks that checkbox with specified in|name|label|value is enabled.
	 * Example: Then the field "Email" should be enabled
	 * Example: Then the "Email" field should be enabled
	 *
	 * @Then /^the "(?P<field>(?:[^"]|\\")*)" field should be enabled/
	 * @Then /^the field "(?P<field>(?:[^"]|\\")*)" should be enabled/
	 */
	public function stepFieldShouldBeEnabled($field) {
		$page = $this->getSession()->getPage();
		$fieldElement = $page->findField($field);
		assertNotNull($fieldElement, sprintf("Field '%s' not found", $field));

		$disabledAttribute = $fieldElement->getAttribute('disabled');

		assertNull($disabledAttribute, sprintf("Failed asserting field '%s' is enabled", $field));
	}

    /**
     * Clicks a link in a specific region (an element identified by a CSS selector, a "data-title" attribute,
     * or a named region mapped to a CSS selector via Behat configuration).
     *
     * Example: Given I follow "Select" in the "header .login-form" region
     * Example: Given I follow "Select" in the "My Login Form" region
     *
     * @Given /^I (?:follow|click) "(?P<link>[^"]*)" in the "(?P<region>[^"]*)" region$/
     */
    public function iFollowInTheRegion($link, $region) {
        $context = $this->getMainContext();
        $regionObj = $context->getRegionObj($region);
        assertNotNull($regionObj);

        $linkObj = $regionObj->findLink($link);
        if (empty($linkObj)) {
			throw new \Exception(sprintf('The link "%s" was not found in the region "%s" 
				on the page %s', $link, $region, $this->getSession()->getCurrentUrl()));
        }

        $linkObj->click();
    }

    /**
     * Fills in a field in a specfic region similar to (@see iFollowInTheRegion or @see iSeeTextInRegion)
     *
     * Example: Given I fill in "Hello" with "World"
     *
     * @Given /^I fill in "(?P<field>[^"]*)" with "(?P<value>[^"]*)" in the "(?P<region>[^"]*)" region$/
     */
    public function iFillinTheRegion($field, $value, $region){
        $context = $this->getMainContext();
        $regionObj = $context->getRegionObj($region);
        assertNotNull($regionObj, "Region Object is null");

        $fieldObj = $regionObj->findField($field);
        if (empty($fieldObj)) {
			throw new \Exception(sprintf('The field "%s" was not found in the region "%s" 
				on the page %s', $field, $region, $this->getSession()->getCurrentUrl()));
        }

        $regionObj->fillField($field, $value);
    }


    /**
     * Asserts text in a specific region (an element identified by a CSS selector, a "data-title" attribute,
     * or a named region mapped to a CSS selector via Behat configuration).
     * Supports regular expressions in text value.
     *
     * Example: Given I should see "My Text" in the "header .login-form" region
     * Example: Given I should not see "My Text" in the "My Login Form" region
     *
     * @Given /^I should (?P<negate>(?:(not |)))see "(?P<text>[^"]*)" in the "(?P<region>[^"]*)" region$/
     */
    public function iSeeTextInRegion($negate, $text, $region) {
        $context = $this->getMainContext();
        $regionObj = $context->getRegionObj($region);
        assertNotNull($regionObj);

        $actual = $regionObj->getText();
        $actual = preg_replace('/\s+/u', ' ', $actual);
        $regex  = '/'.preg_quote($text, '/').'/ui';

        if(trim($negate)) {
            if (preg_match($regex, $actual)) {
                $message = sprintf(
                    'The text "%s" was found in the text of the "%s" region on the page %s.',
                    $text,
                    $region,
                    $this->getSession()->getCurrentUrl()
                );

                throw new \Exception($message);
            }
        } else {
            if (!preg_match($regex, $actual)) {
                $message = sprintf(
                    'The text "%s" was not found anywhere in the text of the "%s" region on the page %s.',
                    $text,
                    $region,
                    $this->getSession()->getCurrentUrl()
                );

                throw new \Exception($message);
            }
        }

    }

	/**
	 * Selects the specified radio button
	 *
	 * @Given /^I select the "([^"]*)" radio button$/
	 */
	public function iSelectTheRadioButton($radioLabel) {
		$session = $this->getSession();
		$radioButton = $session->getPage()->find('named', array(
                      'radio', $this->getSession()->getSelectorsHandler()->xpathLiteral($radioLabel)
                  ));
		assertNotNull($radioButton);
		$session->getDriver()->click($radioButton->getXPath());
	}

    /**
     * @Then /^the "([^"]*)" table should contain "([^"]*)"$/
     */
    public function theTableShouldContain($selector, $text) {
        $table = $this->getTable($selector);

        $element = $table->find('named', array('content', "'$text'"));
        assertNotNull($element, sprintf('Element containing `%s` not found in `%s` table', $text, $selector));
    }

    /**
     * @Then /^the "([^"]*)" table should not contain "([^"]*)"$/
     */
    public function theTableShouldNotContain($selector, $text) {
        $table = $this->getTable($selector);

        $element = $table->find('named', array('content', "'$text'"));
        assertNull($element, sprintf('Element containing `%s` not found in `%s` table', $text, $selector));
    }

    /**
     * @Given /^I click on "([^"]*)" in the "([^"]*)" table$/
     */
    public function iClickOnInTheTable($text, $selector) {
        $table = $this->getTable($selector);

        $element = $table->find('xpath', sprintf('//*[count(*)=0 and contains(.,"%s")]', $text));
        assertNotNull($element, sprintf('Element containing `%s` not found', $text));
        $element->click();
    }

    /**
     * Finds the first visible table by various factors:
     * - table[id]
     * - table[title]
     * - table *[class=title]
     * - fieldset[data-name] table
     * - table caption
     *
     * @return Behat\Mink\Element\NodeElement
     */
    protected function getTable($selector) {
        $selector = $this->getSession()->getSelectorsHandler()->xpathLiteral($selector);
        $page = $this->getSession()->getPage();
        $candidates = $page->findAll(
            'xpath',
            $this->getSession()->getSelectorsHandler()->selectorToXpath(
                "xpath", ".//table[(./@id = $selector or  contains(./@title, $selector))]"
            )
        );

        // Find tables by a <caption> field
		$candidates += $page->findAll('xpath', "//table//caption[contains(normalize-space(string(.)), 
			$selector)]/ancestor-or-self::table[1]");

        // Find tables by a .title node
		$candidates += $page->findAll('xpath', "//table//*[@class='title' and contains(normalize-space(string(.)), 
			$selector)]/ancestor-or-self::table[1]");

        // Some tables don't have a visible title, so look for a fieldset with data-name instead
        $candidates += $page->findAll('xpath', "//fieldset[@data-name=$selector]//table");

        assertTrue((bool)$candidates, 'Could not find any table elements');

        $table = null;
        foreach($candidates as $candidate) {
            if(!$table && $candidate->isVisible()) {
                $table = $candidate;
            }
        }

        assertTrue((bool)$table, 'Found table elements, but none are visible');

        return $table;
    }

	/**
	 * Checks the order of two texts.
	 * Assumptions: the two texts appear in their conjunct parent element once
	 * @Then /^I should see the text "(?P<textBefore>(?:[^"]|\\")*)" (before|after) the text "(?P<textAfter>(?:[^"]|\\")*)" in the "(?P<element>[^"]*)" element$/
	 */
	public function theTextBeforeAfter($textBefore, $order, $textAfter, $element) {
		$ele = $this->getSession()->getPage()->find('css', $element);
		assertNotNull($ele, sprintf('%s not found', $element));

		// Check both of the texts exist in the element
		$text = $ele->getText();
		assertTrue(strpos($text, $textBefore) !== 'FALSE', sprintf('%s not found in the element %s', $textBefore, $element));
		assertTrue(strpos($text, $textAfter) !== 'FALSE', sprintf('%s not found in the element %s', $textAfter, $element));

		/// Use strpos to get the position of the first occurrence of the two texts (case-sensitive)
		// and compare them with the given order (before or after)
		if($order === 'before') {
			assertTrue(strpos($text, $textBefore) < strpos($text, $textAfter));
		} else {
			assertTrue(strpos($text, $textBefore) > strpos($text, $textAfter));
		}
	}

	/**
	* Wait until a certain amount of seconds till I see an element  identified by a CSS selector.
	*
	* Example: Given I wait for 10 seconds until I see the ".css_element" element
	*
	* @Given /^I wait for (\d+) seconds until I see the "([^"]*)" element$/
	**/
	public function iWaitXUntilISee($wait, $selector) {
		$page = $this->getSession()->getPage();

		$this->spin(function($page) use ($page, $selector){
			$element = $page->find('css', $selector);

			if(empty($element)) {
				return false;
			} else {
				return $element->isVisible();
			}
		});
	}

    /**
     * Wait until a particular element is visible, using a CSS selector. Useful for content loaded via AJAX, or only
     * populated after JS execution.
     *
     * Example: Given I wait until I see the "header .login-form" element
     *
     * @Given /^I wait until I see the "([^"]*)" element$/
     */
    public function iWaitUntilISee($selector) {
        $page = $this->getSession()->getPage();
        $this->spin(function($page) use ($page, $selector){
            $element = $page->find('css', $selector);
            if(empty($element)){
                return false;
            } else{
                return ($element->isVisible());
            }
        });
    }

    /**
     * Wait until a particular string is found on the page. Useful for content loaded via AJAX, or only populated after
     * JS execution.
     *
     * Example: Given I wait until I see the text "Welcome back, John!"
     *
     * @Given /^I wait until I see the text "([^"]*)"$/
     */
    public function iWaitUntilISeeText($text){
        $page = $this->getSession()->getPage();
        $session = $this->getSession();
        $this->spin(function($page) use ($page, $session, $text) {
            $element = $page->find(
                'xpath',
                $session->getSelectorsHandler()->selectorToXpath("xpath", ".//*[contains(text(), '$text')]")
            );

            if(empty($element)) {
                return false;
            } else {
                return ($element->isVisible());
            }
        });
    }

	/**
	 * @Given /^I scroll to the bottom$/
	 */
	public function iScrollToBottom() {
		$javascript = 'window.scrollTo(0, Math.max(document.documentElement.scrollHeight, document.body.scrollHeight, document.documentElement.clientHeight));';
		$this->getSession()->executeScript($javascript);
	}

	/**
	 * @Given /^I scroll to the top$/
	 */
	public function iScrollToTop() {
		$this->getSession()->executeScript('window.scrollTo(0,0);');
	}

	/**
	 * Scroll to a certain element by label.
	 * Requires an "id" attribute to uniquely identify the element in the document.
	 *
	 * Example: Given I scroll to the "Submit" button
	 * Example: Given I scroll to the "My Date" field
	 *
	 * @Given /^I scroll to the "([^"]*)" (field|link|button)$/
	 */
	public function iScrollToField($locator, $type) {
		$page = $this->getSession()->getPage();
        $el = $page->find('named', array($type, "'$locator'"));
        assertNotNull($el, sprintf('%s element not found', $locator));

        $id = $el->getAttribute('id');
		if(empty($id)) {
			throw new \InvalidArgumentException('Element requires an "id" attribute');
		}

		$js = sprintf("document.getElementById('%s').scrollIntoView(true);", $id);
		$this->getSession()->executeScript($js);
	}

	/**
	 * Scroll to a certain element by CSS selector.
	 * Requires an "id" attribute to uniquely identify the element in the document.
	 *
	 * Example: Given I scroll to the ".css_element" element
	 *
	 * @Given /^I scroll to the "(?P<locator>(?:[^"]|\\")*)" element$/
	 */
	public function iScrollToElement($locator) {
		$el = $this->getSession()->getPage()->find('css', $locator);
		assertNotNull($el, sprintf('The element "%s" is not found', $locator));

		$id = $el->getAttribute('id');
		if(empty($id)) {
			throw new \InvalidArgumentException('Element requires an "id" attribute');
		}

		$js = sprintf("document.getElementById('%s').scrollIntoView(true);", $id);
		$this->getSession()->executeScript($js);
	}

    /**
     * Continuously poll the dom until callback returns true, code copied from
     * (@link http://docs.behat.org/cookbook/using_spin_functions.html)
     * If not found within a given wait period, timeout and throw error
     *
     * @param callback $lambda The function to run continuously
     * @param integer $wait Timeout in seconds
     * @return bool Returns true if the lambda returns successfully
     * @throws \Exception Thrown if the wait threshold is exceeded without the lambda successfully returning
     */
    public function spin($lambda, $wait = 60) {
        for ($i = 0; $i < $wait; $i++) {
            try {
                if($lambda($this)) {
                    return true;
                }
            } catch (\Exception $e) {
                // do nothing
            }

            sleep(1);
        }

        $backtrace = debug_backtrace();

        throw new \Exception(sprintf(
            "Timeout thrown by %s::%s()\n.",
            $backtrace[1]['class'],
            $backtrace[1]['function']
        ));
    }
	
	
	
	/**
	 * We have to catch exceptions and log somehow else otherwise behat falls over
	 */
	protected function logException($e){
		file_put_contents('php://stderr', 'Exception caught: '.$e);
	}
	
}
