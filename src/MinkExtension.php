<?php

namespace SilverStripe\BehatExtension;

use Behat\MinkExtension\ServiceContainer\MinkExtension as BaseMinkExtension;
use SilverStripe\BehatExtension\Compiler\MinkExtensionBaseUrlPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Subclass the main extension in order to get a say in the config compilation.
 * We need to intercept setting the base_url to auto-detect it from SilverStripe configuration.
 *
 * Configured by adding `SilverStripe\BehatExtension\MinkExtension` to your behat.yml
 */
class MinkExtension extends BaseMinkExtension
{
    public function process(ContainerBuilder $container)
    {
        parent::process($container);
        $urlPass = new MinkExtensionBaseUrlPass();
        $urlPass->process($container);
    }
}
