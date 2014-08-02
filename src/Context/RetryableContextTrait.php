<?php

namespace SilverStripe\BehatExtension\Context;

trait RetryableContextTrait
{
    /**
     * Invoke callback for a non-empty result with a given timeout
     *
     * @param callable $callback
     * @param int $timeout Number of seconds to retry for
     * @return mixed Result of invoking $try, or null if timed out
     */
    protected function retryUntil($callback, $timeout = 3)
    {
        do {
            $result = $callback();
            if ($result) {
                return $result;
            }
            sleep(1);
        } while (--$timeout >= 0);
        return null;
    }
}
