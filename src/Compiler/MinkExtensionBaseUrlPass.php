<?php

namespace SilverStripe\BehatExtension\Compiler;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

/**
 * Behat\SilverStripe container compilation pass.
 * Passes Base URL available in MinkExtension config.
 * Used for the {@link \SilverStripe\BehatExtension\MinkExtension} subclass.
 *
 * @author Michał Ochman <ochman.d.michal@gmail.com>
 */
class MinkExtensionBaseUrlPass implements CompilerPassInterface
{
    /**
     * Passes MinkExtension's base_url parameter
     *
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        // Set url from environment
        $baseURL = getenv('SS_BASE_URL');
        if (!$baseURL) {
            throw new InvalidArgumentException(
                '"base_url" not configured. Please specify it in your .env config with SS_BASE_URL'
            );
        }
        $container->setParameter('mink.base_url', $baseURL);

        // The Behat\MinkExtension\Extension class copies configuration into an internal hash,
        // we need to follow this pattern to propagate our changes.
        $parameters = $container->getParameter('mink.parameters');
        $parameters['base_url'] = $container->getParameter('mink.base_url');
        $container->setParameter('mink.parameters', $parameters);
    }
}
