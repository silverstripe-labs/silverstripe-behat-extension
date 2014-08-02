<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;

/**
 * Represents a behat context which is aware of a main {@see SilverStripeContext} context.
 *
 * Nested contexts are bootstrapped by SilverStripeContext::gatherContexts()
 */
trait MainContextAwareTrait
{
    /**
     * @var SilverStripeContext
     */
    protected $mainContext;

    /**
     * Get the main context
     *
     * @return SilverStripeContext
     */
    public function getMainContext()
    {
        return $this->mainContext;
    }

    /**
     * @param SilverStripeContext $mainContext
     * @return $this
     */
    public function setMainContext($mainContext)
    {
        $this->mainContext = $mainContext;
        return $this;
    }

    /**
     * Helper method to detect the main context
     *
     * @BeforeScenario
     * @param BeforeScenarioScope $scope
     */
    public function detectMainContext(BeforeScenarioScope $scope)
    {
        $environment = $scope->getEnvironment();
        if (! $environment instanceof InitializedContextEnvironment) {
            throw new \LogicException("No context available for this environment");
        }

        $contexts = $environment->getContexts();
        foreach ($contexts as $context) {
            if ($context instanceof SilverStripeContext) {
                $this->setMainContext($context);
                return;
            }
        }

        throw new \LogicException("No SilverStripeContext is configured");
    }
}
