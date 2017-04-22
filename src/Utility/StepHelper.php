<?php

namespace SilverStripe\BehatExtension\Utility;

use Behat\Gherkin\Node\FeatureNode;
use Behat\Gherkin\Node\NodeInterface;
use Behat\Gherkin\Node\ScenarioInterface;
use \Exception;

/**
 * Helpers for working with steps
 *
 * Note: Add `@retry` to any feature / scenario to make it retryable
 */
trait StepHelper
{
    /**
     * Get scenario from step in a feature node
     * See https://github.com/Behat/Behat/issues/653
     *
     * @param FeatureNode $feature
     * @param NodeInterface $step
     * @return ScenarioInterface
     */
    protected function getStepScenario(FeatureNode $feature, NodeInterface $step)
    {
        $scenario = null;
        foreach ($feature->getScenarios() as $nextScenario) {
            if ($nextScenario->getLine() > $step->getLine()) {
                break;
            }
            $scenario = $nextScenario;
        }
        return $scenario;
    }

    /**
     * Retry until no exceptions are thrown, or until
     * $timeout seconds are reached.
     *
     * If timeout reached, re-throws the first exception.
     *
     * @param callable $callback
     * @param int $timeout
     * @return mixed
     * @throws Exception
     */
    protected function retryThrowable($callback, $timeout = 3)
    {
        $firstEx = null;
        do {
            try {
                return call_user_func($callback);
            } catch (Exception $ex) {
                if (!$firstEx) {
                    $firstEx = $ex;
                }
            }
            sleep(1);
        } while (--$timeout >= 0);
        throw $firstEx;
    }
}
