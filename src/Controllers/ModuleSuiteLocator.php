<?php

namespace SilverStripe\BehatExtension\Controllers;

use Behat\Testwork\Cli\Controller;
use Behat\Testwork\Suite\Cli\SuiteController;
use Behat\Testwork\Suite\ServiceContainer\SuiteExtension;
use Behat\Testwork\Suite\SuiteRegistry;
use Exception;
use SilverStripe\Core\Manifest\Module;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

/**
 * Locates test suite configuration based on module name.
 *
 * @see SuiteController for similar core behat controller
 */
class ModuleSuiteLocator implements Controller
{
    use ModuleCommandTrait;

    /**
     * @var Container
     */
    protected $container;

    /**
     * @var SuiteRegistry
     */
    protected $registry;

    /**
     * Cache of configured suites
     *
     * @see SuiteExtension Which registers these
     * @var array
     */
    private $suiteConfigurations = array();

    /**
     * Init suite locator
     *
     * @param ContainerInterface $container
     * @param SuiteRegistry $registry
     */
    public function __construct(
        ContainerInterface $container,
        SuiteRegistry $registry
    ) {
        $this->container = $container;
        $this->registry = $registry;
        $this->suiteConfigurations = $container->getParameter('suite.configurations');
    }

    /**
     * Configures command to be able to process it later.
     *
     * @param Command $command
     */
    public function configure(Command $command)
    {
        $command->addArgument(
            'module',
            InputArgument::OPTIONAL,
            "Specific module suite to load. "
                . "Must be in @modulename format. Supports @vendor/name syntax for vendor installed modules. "
                . "Ensure that a modulename/behat.yml exists containing a behat suite of the same name."
        );
    }

    /**
     * Processes data from container and console input.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @throws \RuntimeException
     * @return null
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$input->hasArgument('module')) {
            return null;
        }

        // Don't register config if init
        if ($input->getOption('init')) {
            return;
        }

        // Get module
        $moduleName = $input->getArgument('module');
        $module = $this->getModule($moduleName);

        // Suite name always omits vendor
        $suiteName = $module->getShortName();

        // If suite is already configured in the root, switch to it and return
        if (isset($this->suiteConfigurations[$suiteName])) {
            $config = $this->suiteConfigurations[$suiteName];
            $this->registry->registerSuiteConfiguration(
                $suiteName,
                $config['type'],
                $config['settings']
            );
            return null;
        }

        // Suite doesn't exist, so load dynamically from nested `behat.yml`
        $config = $this->loadSuiteConfiguration($suiteName, $module);
        $this->registry->registerSuiteConfiguration(
            $suiteName,
            $config['type'],
            $config['settings']
        );
        return null;
    }

    /**
     * Get behat.yml configured for this module
     *
     * @param Module $module
     * @return string Path to config
     */
    protected function findModuleConfig(Module $module)
    {
        $pathSuffix = $this->container->getParameter('silverstripe_extension.context.features_path');
        $path = $module->getPath();

        // Find all candidate paths
        foreach ([ "{$path}/", "{$path}/{$pathSuffix}"] as $parent) {
            foreach ([$parent.'behat.yml', $parent.'.behat.yml'] as $candidate) {
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }
        throw new \InvalidArgumentException("No behat.yml found for module " . $module->getName());
    }

    /**
     * Load configuration dynamically from yml
     *
     * @param string $suite Suite name
     * @param Module $module
     * @return array
     * @throws Exception
     */
    protected function loadSuiteConfiguration($suite, Module $module)
    {
        $path = $this->findModuleConfig($module);
        $yamlParser = new Parser();
        $config = $yamlParser->parse(file_get_contents($path));
        if (empty($config['default']['suites'][$suite])) {
            throw new Exception("Path {$path} does not contain default.suites.{$suite} config");
        }
        $suiteConfig = $config['default']['suites'][$suite];
        // Resolve variables
        $resolvedConfig = $this->container->getParameterBag()->resolveValue($suiteConfig);
        return [
            'type' => null, // @todo figure out what this is for
            'settings' => $resolvedConfig,
        ];
    }
}
