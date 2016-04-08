<?php
/**
 * Resets the fake database used for fake web services
 * after a test scenario finishes. The database is initialized
 * on each request.
 */
class FakeManagerTestSessionExtension extends Extension {

    public function updateBaseFields($fields) {
        // Don't need YAML fixtures
        $fields->removeByname('fixture');
    }

    public function fakeDatabasePath() {
        return TEMP_FOLDER . '/' . uniqid() . '.json';
    }

    public function templatePathRelative() {
        return '/mysite/tests/fixtures/FakeDatabase.json';
    }

    public function updateStartForm($form) {
        $fields = $form->Fields();

        // Default to last database template
        $templateField = $fields->dataFieldByName('importDatabasePath');
        $templates = $templateField->getSource();
        end($templates);

        $templateField->setValue(key($templates));

        $fields->push(new CheckboxField('useFakeManager', 'Use webservice fakes?', 1));
        $fields->push(new HiddenField('fakeDatabasePath', null, $this->fakeDatabasePath()));
        $templatePathRelative = $this->templatePathRelative();

        $fields->push(
            DropdownField::create(
                'fakeDatabaseTemplatePath',
                false,
                array(
                    BASE_PATH . $templatePathRelative => $templatePathRelative
                )
            )
                ->setEmptyString('none')
                ->setValue(BASE_PATH . $templatePathRelative)
        );
    }

    /**
     * This needs to handle two distinct cases:
     * - Test Session being created by behat (calling TestSessionEnvironment directly), and
     * - Test Session being created by browsing to dev/testsession and submitting the form.
     *
     * The form is modified above (@see self::updateStartForm()) and we need to ensure we respect those selections, if
     * necessary. If it wasn't submitted via a form, then we can set the fakes up as required for behat.
     *
     * @param $state Array of state passed from TestSessionEnvironment
     */
    public function onBeforeStartTestSession(&$state) {
        // Only set fake database paths when using fake manager
        if(empty($state['useFakeManager'])) {
            unset($state['fakeDatabasePath']);
            unset($state['fakeDatabaseTemplatePath']);
        }

        if(
            $state
            && !empty($state['useFakeManager'])
            && !empty($state['fakeDatabaseTemplatePath'])
            && !empty($state['fakeDatabasePath'])
        ) {
            // Copy template database, to keep it clean for other runs
            copy($state['fakeDatabaseTemplatePath'], $state['fakeDatabasePath']);
        }

        // Running via behat, so we figure out the fake stuff for ourself
        // @see self::updateStartForm()
        if($state && !empty($state['useFakeManager'])) {
            $state['useFakeManager'] = 1;
            $state['fakeDatabaseTemplatePath'] = BASE_PATH . $this->templatePathRelative();
            if(empty($state['fakeDatabasePath'])) {
                $state['fakeDatabasePath'] = $this->fakeDatabasePath();
            }
            copy($state['fakeDatabaseTemplatePath'], $state['fakeDatabasePath']);
            chmod($state['fakeDatabasePath'], 0777);
        }

        return $state;
    }

    /**
     * Only used for manual testing, not on Behat runs.
     */
    public function onBeforeClear() {
        $testEnv = Injector::inst()->get('TestSessionEnvironment');
        $state = $testEnv->getState();

        if($state && isset($state->useFakeManager) && $state->useFakeManager) {
            $this->resetFakeManager();
        }
    }

    /**
     * Only used for manual testing, not on Behat runs.
     */
    public function onBeforeEndTestSession() {
        $state = $this->owner->getState();

        if($state && isset($state->useFakeManager) && $state->useFakeManager) {
            $this->resetFakeManager();
        }
    }

    /**
     * A similar reset is also performed in Mysite\Tests\Behaviour\FeatureContext->resetFakeDatabase().
     * We can't reset Behat CLI runs through this measure because the CLI has a persistent connection
     * to the underlying SQLite database file, so the browser can't remove it.
     */
    protected function resetFakeManager() {
        $state = $this->owner->getState();

        if($state) {
            $manager = Injector::inst()->get(
                'FakeManager',
                true,
                array(new FakeDatabase($state->fakeDatabasePath))
            );
            $manager->getDb()->reset();
        }
    }

}
