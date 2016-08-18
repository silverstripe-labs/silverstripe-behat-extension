<?php

namespace SilverStripe\BehatExtension;

/**
 * Subclass the main extension in order to get a say in the config compilation.
 * We need to intercept setting the base_url to auto-detect it from SilverStripe configuration.
 */
class MinkExtension extends \Behat\MinkExtension\Extension
{

    public function getCompilerPasses()
    {
        return array_merge(
            array(new Compiler\MinkExtensionBaseUrlPass()),
            parent::getCompilerPasses()
        );
    }
}
