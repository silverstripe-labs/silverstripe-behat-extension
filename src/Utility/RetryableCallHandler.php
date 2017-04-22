<?php

namespace SilverStripe\BehatExtension\Utility;

use Behat\Behat\Definition\Call\DefinitionCall;
use Behat\Testwork\Call\Call;
use Behat\Testwork\Call\CallResult;
use Behat\Testwork\Call\Exception\CallErrorException;
use Behat\Testwork\Call\Handler\CallHandler;
use Behat\Testwork\Call\Handler\RuntimeCallHandler;
use Exception;

/**
 * Replaces RuntimeCallHandler with a retry feature
 * All scenarios or features OPT-IN to retry behaviour with
 * the @retry tag.
 *
 * Note: most of this class is duplicated (sad face) due to final class
 * @see RuntimeCallHandler
 */
class RetryableCallHandler implements CallHandler
{
    use StepHelper;

    const RETRY_TAG = 'retry';

    /**
     * @var integer
     */
    private $errorReportingLevel;

    /**
     * @var bool
     */
    private $obStarted = false;

    /**
     * @var int
     */
    protected $retrySeconds;

    /**
     * Initializes executor.
     *
     * @param int $errorReportingLevel
     * @param int $retrySeconds
     */
    public function __construct($errorReportingLevel = E_ALL, $retrySeconds = 3)
    {
        $this->errorReportingLevel = $errorReportingLevel;
        $this->retrySeconds = $retrySeconds;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsCall(Call $call)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function handleCall(Call $call)
    {
        $this->startErrorAndOutputBuffering($call);
        $result = $this->executeCall($call);
        $this->stopErrorAndOutputBuffering();

        return $result;
    }

    /**
     * Used as a custom error handler when step is running.
     *
     * @see set_error_handler()
     *
     * @param integer $level
     * @param string  $message
     * @param string  $file
     * @param integer $line
     *
     * @return Boolean
     *
     * @throws CallErrorException
     */
    public function handleError($level, $message, $file, $line)
    {
        if ($this->errorLevelIsNotReportable($level)) {
            return false;
        }

        throw new CallErrorException($level, $message, $file, $line);
    }

    /**
     * Executes single call.
     *
     * @param Call $call
     *
     * @return CallResult
     */
    private function executeCall(Call $call)
    {
        $callable = $call->getBoundCallable();
        $arguments = $call->getArguments();
        $retry = $this->isCallRetryable($call);
        $return = $exception = null;

        try {
            // Determine whether to call with retries
            if ($retry) {
                $return = $this->retryThrowable(function () use ($callable, $arguments) {
                    return call_user_func_array($callable, $arguments);
                }, $this->retrySeconds);
            } else {
                $return = call_user_func_array($callable, $arguments);
            }
        } catch (Exception $caught) {
            $exception = $caught;
        }

        $stdOut = $this->getBufferedStdOut();

        return new CallResult($call, $return, $exception, $stdOut);
    }

    /**
     * Returns buffered stdout.
     *
     * @return null|string
     */
    private function getBufferedStdOut()
    {
        return ob_get_length() ? ob_get_contents() : null;
    }

    /**
     * Starts error handler and stdout buffering.
     *
     * @param Call $call
     */
    private function startErrorAndOutputBuffering(Call $call)
    {
        $errorReporting = $call->getErrorReportingLevel() ? : $this->errorReportingLevel;
        set_error_handler(array($this, 'handleError'), $errorReporting);
        $this->obStarted = ob_start();
    }

    /**
     * Stops error handler and stdout buffering.
     */
    private function stopErrorAndOutputBuffering()
    {
        if ($this->obStarted) {
            ob_end_clean();
        }
        restore_error_handler();
    }

    /**
     * Checks if provided error level is not reportable.
     *
     * @param integer $level
     *
     * @return Boolean
     */
    private function errorLevelIsNotReportable($level)
    {
        return !(error_reporting() & $level);
    }

    /**
     * Determine if the call is retryable
     *
     * @param Call $call
     * @return bool
     */
    protected function isCallRetryable(Call $call)
    {
        if (!($call instanceof DefinitionCall)) {
            return false;
        }
        $feature = $call->getFeature();
        if ($feature->hasTag(self::RETRY_TAG)) {
            return true;
        }
        $scenario = $this->getStepScenario($feature, $call->getStep());
        return $scenario && $scenario->hasTag(self::RETRY_TAG);
    }
}
