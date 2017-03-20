<?php

namespace SilverStripe\BehatExtension\Console\Processor;

use SilverStripe\Core\Manifest\ModuleLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Behat\Behat\Console\Processor\LocatorProcessor as BaseProcessor;

/**
 * Path locator processor.
 */
class LocatorProcessor extends BaseProcessor
{
    private $container;

    /**
     * Constructs processor.
     *
     * @param ContainerInterface $container Container instance
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Configures command to be able to process it later.
     *
     * @param Command $command
     */
    public function configure(Command $command)
    {
        $command->addArgument(
            'features',
            InputArgument::OPTIONAL,
            "Feature(s) to run. Could be:".
            "\n- a dir (<comment>src/to/module/Features/</comment>), " .
            "\n- a feature (<comment>src/to/module/Features/*.feature</comment>), " .
            "\n- a scenario at specific line (<comment>src/to/module/Features/*.feature:10</comment>). " .
            "\n- Also, you can use short module notation (<comment>@moduleName/*.feature:10</comment>)"
        );
    }

    /**
     * Processes data from container and console input.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \RuntimeException
     */
    public function process(InputInterface $input, OutputInterface $output)
    {
        $featuresPath = $input->getArgument('features');

        // Can't use 'behat.paths.base' since that's locked at this point to base folder (not module)
        $pathSuffix   = $this->container->getParameter('behat.silverstripe_extension.context.path_suffix');

        $currentModuleName = null;
        // get module specified in behat.yml
        $currentModuleName = $this->container->getParameter('behat.silverstripe_extension.module');

        // get module from short notation if path starts from @
        if ($featuresPath && preg_match('/^\@([^\/\\\\]+)(.*)$/', $featuresPath, $matches)) {
            $currentModuleName = $matches[1];
            // TODO Replace with proper module loader once AJShort's changes are merged into core
            $module = ModuleLoader::instance()->getManifest()->getModule($currentModuleName);
            if (!$module) {
                throw new \InvalidArgumentException(sprintf('Module "%s" not found', $currentModuleName));
            }
            $currentModulePath = $module->getPath();
            $featuresPath = str_replace(
                '@'.$currentModuleName,
                $currentModulePath.DIRECTORY_SEPARATOR.$pathSuffix,
                $featuresPath
            );
        // get module from provided features path
        } elseif (!$currentModuleName && $featuresPath) {
            $path = realpath(preg_replace('/\.feature\:.*$/', '.feature', $featuresPath));
            $modules = ModuleLoader::instance()->getManifest()->getModules();
            $currentModulePath = null;
            foreach ($modules as $module) {
                $modulePath = $module->getPath();
                if (false !== strpos($path, realpath($modulePath))) {
                    $currentModuleName = $module->getName();
                    $currentModulePath = realpath($modulePath);
                    break;
                }
            }
            if (!$currentModulePath) {
                throw new \InvalidArgumentException(sprintf('Module not found in path "%s"', $featuresPath));
            }
            $featuresPath = $currentModulePath.DIRECTORY_SEPARATOR.$pathSuffix.DIRECTORY_SEPARATOR.$featuresPath;
        // if module is configured for profile and feature provided
        } elseif ($currentModuleName && $featuresPath) {
            $module = ModuleLoader::instance()->getManifest()->getModule($currentModuleName);
            if (!$module) {
                throw new \InvalidArgumentException(sprintf('Module "%s" not found', $currentModuleName));
            }
            $currentModulePath = $module->getPath();
            $featuresPath = $currentModulePath.DIRECTORY_SEPARATOR.$pathSuffix.DIRECTORY_SEPARATOR.$featuresPath;
        }

        if ($input->getOption('namespace')) {
            $namespace = $input->getOption('namespace');
        } else {
            $namespace = ucfirst($currentModuleName);
        }

        if ($currentModuleName) {
            $this->container
                ->get('behat.silverstripe_extension.context.class_guesser')
                // TODO Improve once modules can declare their own namespaces consistently
                ->setNamespaceBase($namespace);
        }

        $this->container
            ->get('behat.console.command')
            ->setFeaturesPaths($featuresPath ? array($featuresPath) : array());
    }
}
