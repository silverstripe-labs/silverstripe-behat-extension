<?php

namespace SilverStripe\BehatExtension\Context;

/*
 * This file is part of the Behat/SilverStripeExtension
 *
 * (c) Michał Ochman <ochman.d.michal@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Behat\MinkExtension\Context\MinkAwareContext;

/**
 * SilverStripe aware interface for contexts.
 *
 * @author Michał Ochman <ochman.d.michal@gmail.com>
 */
interface SilverStripeAwareContext extends MinkAwareContext
{
    /**
     * Sets SilverStripe instance.
     *
     * @param string $databaseName Temp database name
     */
    public function setDatabase($databaseName);

    /**
     * Marks steps as AJAX steps for special treatment
     *
     * @param array $ajaxSteps Array of step name parts to match
     */
    public function setAjaxSteps($ajaxSteps);

    /**
     * Set timeout in millisceonds
     *
     * @param int $ajaxTimeout
     */
    public function setAjaxTimeout($ajaxTimeout);

    /**
     * Set admin url
     *
     * @param string $adminUrl
     */
    public function setAdminUrl($adminUrl);

    /**
     * Set login url
     *
     * @param string $loginUrl
     */
    public function setLoginUrl($loginUrl);

    /**
     * Set path to screenshots dir
     *
     * @param string $screenshotPath
     */
    public function setScreenshotPath($screenshotPath);

    /**
     * I have no idea
     *
     * @param $regionMap
     */
    public function setRegionMap($regionMap);
}
