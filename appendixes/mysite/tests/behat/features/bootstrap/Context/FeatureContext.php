<?php

namespace Mysite\Test\Behaviour;

use SilverStripe\BehatExtension\Context\SilverStripeContext,
    SilverStripe\BehatExtension\Context\BasicContext,
    SilverStripe\BehatExtension\Context\LoginContext,
    SilverStripe\BehatExtension\Context\FixtureContext,
    SilverStripe\Framework\Test\Behaviour\CmsFormsContext,
    SilverStripe\Framework\Test\Behaviour\CmsUiContext,
    SilverStripe\Cms\Test\Behaviour;

// PHPUnit
require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

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

        $manager = \Injector::inst()->get(
            'FakeManager',
            true,
            // Creates a new database automatically. Session doesn't exist here yet,
            // so we need to take fake database path from internal config.
            // The same path is then set in the browser session
            // and reused across scenarios (see resetFakeDatabase()).
            array(new \FakeDatabase($this->getFakeDatabasePath()))
        );
        
        $this->manager = $manager;
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

    /**
     * "Shares" the database with web requests, see
     * {@link MeridianFakeManagerControllerExtension}
     */
    public function getTestSessionState() {
        return array_merge(
            parent::getTestSessionState(),
            array(
                'useFakeManager' => true,
                'importDatabasePath' => BASE_PATH .'/mysite/tests/fixtures/SS-sample.sql',
                'requireDefaultRecords' => false,
                'fakeDatabasePath' => $this->getFakeDatabasePath(),
            )
        );
    }

    public function getFakeDatabasePath() {
        return BASE_PATH . '/FakeDatabase.json';
    }
    
    /**
     * @BeforeScenario
     */
    public function resetFakeDatabase() {
        $this->manager->getDb()->reset(true);
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
