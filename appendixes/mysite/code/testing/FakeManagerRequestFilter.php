<?php
/**
 * Registers fake web services which are connected to a temporary database.
 * The registration is for the lifetime of the current request only,
 * but the database can persist beyond that, and share state with other
 * web requests as well as Behat CLI execution.
 *
 * The database is reset through..TBC
 */
class FakeManagerRequestFilter {

    public function preRequest($req, $session, $model) {
        if(class_exists('TestSessionEnvironment')) {
            // Set in Mysite\Test\Behaviour\FeatureContext
            $testState = Injector::inst()->get('TestSessionEnvironment')->getState();

            if($testState && isset($testState->fakeDatabasePath) && $testState->fakeDatabasePath) {
                $fakeDb = new FakeDatabase($testState->fakeDatabasePath);
                Injector::inst()->get('FakeManager', false, array($fakeDb));
            }
        }
    }

    public function postRequest() {
    }
}