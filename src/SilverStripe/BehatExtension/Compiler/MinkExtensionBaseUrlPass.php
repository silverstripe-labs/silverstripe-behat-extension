<?php

namespace SilverStripe\BehatExtension\Compiler;

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
        $frameworkPath = $container->getParameter('behat.silverstripe_extension.framework_path');

        global $_FILE_TO_URL_MAPPING;
        if ($container->getParameter('behat.mink.base_url')) {
            // If base_url is already defined, also set it in the SilverStripe mapping
            $_FILE_TO_URL_MAPPING[dirname($frameworkPath)] = $container->getParameter('behat.mink.base_url');
        } elseif ($envPath = $this->findEnvironmentConfigFile($frameworkPath)) {
            // Otherwise try to retrieve it from _ss_environment
            include_once $envPath;
            if (isset($_FILE_TO_URL_MAPPING)
                && !($container->hasParameter('behat.mink.base_url') && $container->getParameter('behat.mink.base_url'))
            ) {
                $baseUrl = $this->findBaseUrlFromMapping(dirname($frameworkPath), $_FILE_TO_URL_MAPPING);
                if ($baseUrl) {
                    $container->setParameter('behat.mink.base_url', $baseUrl);
                }
            }
        }

        if (!$container->getParameter('behat.mink.base_url')) {
            throw new \InvalidArgumentException(
                '"base_url" not configured. Please specify it in your behat.yml configuration, ' .
                'or in your _ss_environment.php configuration through $_FILE_TO_URL_MAPPING'
            );
        }

        // The Behat\MinkExtension\Extension class copies configuration into an internal hash,
        // we need to follow this pattern to propagate our changes.
        $parameters = $container->getParameter('behat.mink.parameters');
        $parameters['base_url'] = $container->getParameter('behat.mink.base_url');
        $container->setParameter('behat.mink.parameters', $parameters);
    }

    /**
     * Try to auto-detect host for webroot based on _ss_environment.php data (unless explicitly set in behat.yml)
     * Copied logic from Core.php, because it needs to be executed prior to {@link SilverStripeAwareInitializer}.
     *
     * @param String Absolute start path to search upwards from
     * @return Boolean Absolute path to environment file
     */
    protected function findEnvironmentConfigFile($path)
    {
        $envPath = null;
        $envFile = '_ss_environment.php'; //define the name of the environment file
        $path = '.'; //define the dir to start scanning from (have to add the trailing slash)

        //check this dir and every parent dir (until we hit the base of the drive)
        do {
            $path = realpath($path) . '/';
            //if the file exists, then we include it, set relevant vars and break out
            if (file_exists($path . $envFile)) {
                $envPath = $path . $envFile;
                break;
            }
        // here we need to check that the real path of the last dir and the next one are
        // not the same, if they are, we have hit the root of the drive
        } while (realpath($path) != realpath($path .= '../'));

        return $envPath;
    }

    /**
     * Copied logic from Core.php, because it needs to be executed prior to {@link SilverStripeAwareInitializer}.
     *
     * @param String Absolute start path to search upwards from
     * @param Array Map of paths to host names
     * @return String URL
     */
    protected function findBaseUrlFromMapping($path, $mapping)
    {
        $fullPath = $path;
        $url = null;
        while ($path && $path != "/" && !preg_match('/^[A-Z]:\\\\$/', $path)) {
            if (isset($mapping[$path])) {
                $url = $mapping[$path] . str_replace(DIRECTORY_SEPARATOR, '/', substr($fullPath, strlen($path)));
                break;
            } else {
                $path = dirname($path); // traverse up
            }
        }

        return $url;
    }
}
