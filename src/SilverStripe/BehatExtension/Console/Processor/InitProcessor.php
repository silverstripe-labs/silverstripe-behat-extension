<?php

namespace SilverStripe\BehatExtension\Console\Processor;

use Symfony\Component\DependencyInjection\ContainerInterface,
    Symfony\Component\Console\Command\Command,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface,
    Symfony\Component\Console\Input\InputOption;

use Behat\Behat\Console\Processor\InitProcessor as BaseProcessor;

/**
 * Initializes a project for Behat usage, creating context files.
 */
class InitProcessor extends BaseProcessor
{
    private $container;

    /**
     * @param ContainerInterface $container Container instance
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param Command $command
     */
    public function configure(Command $command)
    {
        parent::configure($command);
        
        $command->addOption('--namespace', null, InputOption::VALUE_OPTIONAL,
            "Optional namespace for FeatureContext, defaults to <foldername>\\Test\\Behaviour.\n"
        );
    }

    public function process(InputInterface $input, OutputInterface $output)
    {
        // throw exception if no features argument provided
        if (!$input->getArgument('features') && $input->getOption('init')) {
            throw new \InvalidArgumentException('Provide features argument in order to init suite.');
        }

        // initialize bundle structure and exit
        if ($input->getOption('init')) {
            $this->initBundleDirectoryStructure($input, $output);

            exit(0);
        }
    }

    /**
     * Inits bundle directory structure
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function initBundleDirectoryStructure(InputInterface $input, OutputInterface $output)
    {
        // Bootstrap SS so we can use module listing
        $frameworkPath = $this->container->getParameter('behat.silverstripe_extension.framework_path');
        $_GET['flush'] = 1;
        require_once $frameworkPath . '/core/Core.php';
        unset($_GET['flush']);

        $featuresPath = $input->getArgument('features');
        if(!$featuresPath) {
            throw new \InvalidArgumentException('Please specify a module name (e.g. "@mymodule")');
        }

        // Can't use 'behat.paths.base' since that's locked at this point to base folder (not module)
        $pathSuffix   = $this->container->getParameter('behat.silverstripe_extension.context.path_suffix');
        $currentModuleName = null;
        $modules = \SS_ClassLoader::instance()->getManifest()->getModules();
        $currentModuleName = $this->container->getParameter('behat.silverstripe_extension.module');

        // get module from short notation if path starts from @
        if (preg_match('/^\@([^\/\\\\]+)(.*)$/', $featuresPath, $matches)) {
            $currentModuleName = $matches[1];
            // TODO Replace with proper module loader once AJShort's changes are merged into core
            if (!array_key_exists($currentModuleName, $modules)) {
                throw new \InvalidArgumentException(sprintf('Module "%s" not found', $currentModuleName));
            }
            $currentModulePath = $modules[$currentModuleName];
        } 

        if (!$currentModuleName) {
            throw new \InvalidArgumentException('Can not find module to initialize suite.');
        }

        // TODO Retrieve from module definition once that's implemented
        if($input->getOption('namespace')) {
            $namespace = $input->getOption('namespace');
        } else {
            $namespace = ucfirst($currentModuleName);
        }
        $namespace .= '\\' . $this->container->getParameter('behat.silverstripe_extension.context.namespace_suffix');

        $featuresPath = rtrim($currentModulePath.DIRECTORY_SEPARATOR.$pathSuffix,DIRECTORY_SEPARATOR);
        $basePath     = $this->container->getParameter('behat.paths.base').DIRECTORY_SEPARATOR;
        $bootstrapPath = $featuresPath.DIRECTORY_SEPARATOR.'bootstrap';
        $contextPath  = $bootstrapPath.DIRECTORY_SEPARATOR.'Context';

        if (!is_dir($featuresPath)) {
            mkdir($featuresPath, 0777, true);
            mkdir($bootstrapPath, 0777, true);
            // touch($bootstrapPath.DIRECTORY_SEPARATOR.'_manifest_exclude');
            $output->writeln(
                '<info>+d</info> ' .
                str_replace($basePath, '', realpath($featuresPath)) .
                ' <comment>- place your *.feature files here</comment>'
            );
        }

        if (!is_dir($contextPath)) {
            mkdir($contextPath, 0777, true);

            $className = $this->container->getParameter('behat.context.class');
            file_put_contents(
                $contextPath . DIRECTORY_SEPARATOR . $className . '.php',
                strtr($this->getFeatureContextSkelet(), array(
                    '%NAMESPACE%' => $namespace
                ))
            );

            $output->writeln(
                '<info>+f</info> ' .
                str_replace($basePath, '', realpath($contextPath)) . DIRECTORY_SEPARATOR .
                'FeatureContext.php <comment>- place your feature related code here</comment>'
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getFeatureContextSkelet()
    {
return <<<'PHP'
<?php

namespace %NAMESPACE%;

use SilverStripe\BehatExtension\Context\SilverStripeContext,
    SilverStripe\BehatExtension\Context\BasicContext,
    SilverStripe\BehatExtension\Context\LoginContext,
    SilverStripe\BehatExtension\Context\FixtureContext,
    SilverStripe\Framework\Test\Behaviour\CmsFormsContext,
    SilverStripe\Framework\Test\Behaviour\CmsUiContext,
    SilverStripe\Cms\Test\Behaviour;

/**
 * Features context
 *
 * Context automatically loaded by Behat.
 * Uses subcontexts to extend functionality.
 */
class FeatureContext extends SilverStripeContext {
    
    /**
     * @var FixtureFactory
     */
    protected $fixtureFactory;

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param array $parameters context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters) {
        parent::__construct($parameters);

        $this->useContext('BasicContext', new BasicContext($parameters));
        $this->useContext('LoginContext', new LoginContext($parameters));
        $this->useContext('CmsFormsContext', new CmsFormsContext($parameters));
        $this->useContext('CmsUiContext', new CmsUiContext($parameters));

        $fixtureContext = new FixtureContext($parameters);
        $fixtureContext->setFixtureFactory($this->getFixtureFactory());
        $this->useContext('FixtureContext', $fixtureContext);

        // Use blueprints to set user name from identifier
        $factory = $fixtureContext->getFixtureFactory();
        $blueprint = \Injector::inst()->create('FixtureBlueprint', 'Member');
        $blueprint->addCallback('beforeCreate', function($identifier, &$data, &$fixtures) {
            if(!isset($data['FirstName'])) $data['FirstName'] = $identifier;
        });
        $factory->define('Member', $blueprint);

        // Auto-publish pages
        if (class_exists('SiteTree')) {
            foreach(\ClassInfo::subclassesFor('SiteTree') as $id => $class) {
                $blueprint = \Injector::inst()->create('FixtureBlueprint', $class);
                $blueprint->addCallback('afterCreate', function($obj, $identifier, &$data, &$fixtures) {
                    $obj->publish('Stage', 'Live');
                });
                $factory->define($class, $blueprint);
            }
        }
    }

    public function setMinkParameters(array $parameters) {
        parent::setMinkParameters($parameters);
        
        if(isset($parameters['files_path'])) {
            $this->getSubcontext('FixtureContext')->setFilesPath($parameters['files_path']);    
        }
    }

    /**
     * @return FixtureFactory
     */
    public function getFixtureFactory() {
        if(!$this->fixtureFactory) {
            $this->fixtureFactory = \Injector::inst()->create('BehatFixtureFactory');
        }

        return $this->fixtureFactory;
    }

    public function setFixtureFactory(FixtureFactory $factory) {
        $this->fixtureFactory = $factory;
    }

    //
    // Place your definition and hook methods here:
    //
    //    /**
    //     * @Given /^I have done something with "([^"]*)"$/
    //     */
    //    public function iHaveDoneSomethingWith($argument) {
    //        $container = $this->kernel->getContainer();
    //        $container->get('some_service')->doSomethingWith($argument);
    //    }
    //
}

PHP;
    }
}