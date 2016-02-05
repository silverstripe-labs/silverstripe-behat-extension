<?php
/**
 * The "Fake-Manager" instantiates fake objects, mainly to replace webservices with
 * faked implementations. It also provides shortcuts for creating complex fake datasets
 * through FakeDatabase.
 *
 * This class needs to be instantiated early in the application bootstrap process.
 * By default that's implemented through the TestSessionExtension
 * and config.yml.
 *
 * The instantiation is conditional on there being a Behat test actually running
 * (by virtue of there being a TestSession instantiated) by a 'useFakeManager' flag
 * set through the "TestSession" module.
 *
 * Since both the FeatureContext and actual application bootstrap share the same
 * FakeDatabase persisted on disk, they can share state.
 *
 * In terms of mock-data, at time of writing we're using the same sources as unit-test mocks do.
 */
class FakeManager {

    /**
     *
     * @var type
     */
    protected $db;

    /**
     *
     * @var type
     */
    protected $addressRightGateway;

    /**
     *
     * @param FakeDatabase $db
     */
    function __construct($db = null) {
        $testState = Injector::inst()->get('TestSessionEnvironment')->getState();

        if(!$db) {
            $db = new FakeDatabase($testState->fakeDatabasePath);
        }

        $this->db = $db;
    }

    public function setDb($db) {
        $this->db = $db;
    }
    public function getDb() {
        return $this->db;
    }
}