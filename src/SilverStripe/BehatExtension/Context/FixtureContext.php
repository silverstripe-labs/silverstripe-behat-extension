<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\BehatContext;
use Behat\Behat\Event\ScenarioEvent;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Security\Permission;

// PHPUnit
require_once BASE_PATH . '/vendor/phpunit/phpunit/src/Framework/Assert/Functions.php';

/**
 * Context used to create fixtures in the SilverStripe ORM.
 */
class FixtureContext extends BehatContext
{
    protected $context;

    /**
     * @var FixtureFactory
     */
    protected $fixtureFactory;

    /**
     * @var String Absolute path where file fixtures are located.
     * These will automatically get copied to their location
     * declare through the 'Given a file "..."' step defition.
     */
    protected $filesPath;

    /**
     * @var String Tracks all files and folders created from fixtures, for later cleanup.
     */
    protected $createdFilesPaths = array();

    /**
     * @var array Stores the asset tuples.
     */
    protected $createdAssets = array();

    public function __construct(array $parameters)
    {
        $this->context = $parameters;
    }

    public function getSession($name = null)
    {
        return $this->getMainContext()->getSession($name);
    }

    /**
     * @return FixtureFactory
     */
    public function getFixtureFactory()
    {
        if (!$this->fixtureFactory) {
            $this->fixtureFactory = Injector::inst()->create(
                'SilverStripe\\Dev\\FixtureFactory',
                'FixtureContextFactory'
            );
        }
        return $this->fixtureFactory;
    }

    /**
     * @param FixtureFactory $factory
     */
    public function setFixtureFactory(FixtureFactory $factory)
    {
        $this->fixtureFactory = $factory;
    }

    /**
     * @param String
     */
    public function setFilesPath($path)
    {
        $this->filesPath = $path;
    }

    /**
     * @return String
     */
    public function getFilesPath()
    {
        return $this->filesPath;
    }

    /**
     * @BeforeScenario @database-defaults
     */
    public function beforeDatabaseDefaults(ScenarioEvent $event)
    {
        SapphireTest::empty_temp_db();
        DB::get_conn()->quiet();
        $dataClasses = ClassInfo::subclassesFor('SilverStripe\\ORM\\DataObject');
        array_shift($dataClasses);
        foreach ($dataClasses as $dataClass) {
            \singleton($dataClass)->requireDefaultRecords();
        }
    }

    /**
     * @AfterScenario
     */
    public function afterResetDatabase(ScenarioEvent $event)
    {
        SapphireTest::empty_temp_db();
    }

    /**
     * @AfterScenario
     */
    public function afterResetAssets(ScenarioEvent $event)
    {
        $store = $this->getAssetStore();
        if (is_array($this->createdAssets)) {
            foreach ($this->createdAssets as $asset) {
                $store->delete($asset['FileFilename'], $asset['FileHash']);
            }
        }
    }

    /**
     * Example: Given a "page" "Page 1"
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)"$/
     */
    public function stepCreateRecord($type, $id)
    {
        $class = $this->convertTypeToClass($type);
        $fields = $this->prepareFixture($class, $id);
        $this->fixtureFactory->createObject($class, $id, $fields);
    }

    /**
     * Example: Given a "page" "Page 1" has the "content" "My content"
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" has (?:an|a|the) "(.*)" "(.*)"$/
     */
    public function stepCreateRecordHasField($type, $id, $field, $value)
    {
        $class = $this->convertTypeToClass($type);
        $fields = $this->convertFields(
            $class,
            array($field => $value)
        );
        // We should check if this fixture object already exists - if it does, we update it. If not, we create it
        if ($existingFixture = $this->fixtureFactory->get($class, $id)) {
            // Merge existing data with new data, and create new object to replace existing object
            foreach ($fields as $k => $v) {
                $existingFixture->$k = $v;
            }
            $existingFixture->write();
        } else {
            $this->fixtureFactory->createObject($class, $id, $fields);
        }
    }

    /**
     * Example: Given a "page" "Page 1" with "URL"="page-1" and "Content"="my page 1"
     * Example: Given the "page" "Page 1" has "URL"="page-1" and "Content"="my page 1"
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" (?:with|has) (".*)$/
     */
    public function stepCreateRecordWithData($type, $id, $data)
    {
        $class = $this->convertTypeToClass($type);
        preg_match_all(
            '/"(?<key>[^"]+)"\s*=\s*"(?<value>[^"]+)"/',
            $data,
            $matches
        );
        $fields = $this->convertFields(
            $class,
            array_combine($matches['key'], $matches['value'])
        );
        $fields = $this->prepareFixture($class, $id, $fields);
        // We should check if this fixture object already exists - if it does, we update it. If not, we create it
        if ($existingFixture = $this->fixtureFactory->get($class, $id)) {
            // Merge existing data with new data, and create new object to replace existing object
            foreach ($fields as $k => $v) {
                $existingFixture->$k = $v;
            }
            $existingFixture->write();
        } else {
            $this->fixtureFactory->createObject($class, $id, $fields);
        }
    }

    /**
     * Example: And the "page" "Page 2" has the following data
     * | Content | <blink> |
     * | My Property | foo |
     * | My Boolean | bar |
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" has the following data$/
     */
    public function stepCreateRecordWithTable($type, $id, $null, TableNode $fieldsTable)
    {
        $class = $this->convertTypeToClass($type);
        // TODO Support more than one record
        $fields = $this->convertFields($class, $fieldsTable->getRowsHash());
        $fields = $this->prepareFixture($class, $id, $fields);

        // We should check if this fixture object already exists - if it does, we update it. If not, we create it
        if ($existingFixture = $this->fixtureFactory->get($class, $id)) {
            // Merge existing data with new data, and create new object to replace existing object
            foreach ($fields as $k => $v) {
                $existingFixture->$k = $v;
            }
            $existingFixture->write();
        } else {
            $this->fixtureFactory->createObject($class, $id, $fields);
        }
    }

    /**
     * Example: Given the "page" "Page 1.1" is a child of the "page" "Page1".
     * Note that this change is not published by default
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" is a ([^\s]*) of (?:an|a|the) "([^"]+)" "([^"]+)"/
     */
    public function stepUpdateRecordRelation($type, $id, $relation, $relationType, $relationId)
    {
        $class = $this->convertTypeToClass($type);

        $relationClass = $this->convertTypeToClass($relationType);
        $relationObj = $this->fixtureFactory->get($relationClass, $relationId);
        if (!$relationObj) {
            $relationObj = $this->fixtureFactory->createObject($relationClass, $relationId);
        }

        $data = array();
        if ($relation == 'child') {
            $data['ParentID'] = $relationObj->ID;
        }

        $obj = $this->fixtureFactory->get($class, $id);
        if ($obj) {
            $obj->update($data);
            $obj->write();
        } else {
            $obj = $this->fixtureFactory->createObject($class, $id, $data);
        }

        switch ($relation) {
            case 'parent':
                $relationObj->ParentID = $obj->ID;
                $relationObj->write();
                break;
            case 'child':
                // already written through $data above
                break;
            default:
                throw new \InvalidArgumentException(sprintf(
                    'Invalid relation "%s"',
                    $relation
                ));
        }
    }

    /**
     * Assign a type of object to another type of object. The base object will be created if it does not exist already.
     * If the last part of the string (in the "X" relation) is omitted, then the first matching relation will be used.
     *
     * @example I assign the "TaxonomyTerm" "For customers" to the "Page" "Page1"
     * @Given /^I assign (?:an|a|the) "([^"]+)" "([^"]+)" to (?:an|a|the) "([^"]+)" "([^"]+)"$/
     */
    public function stepIAssignObjToObj($type, $value, $relationType, $relationId)
    {
        self::stepIAssignObjToObjInTheRelation($type, $value, $relationType, $relationId, null);
    }

    /**
     * Assign a type of object to another type of object. The base object will be created if it does not exist already.
     * If the last part of the string (in the "X" relation) is omitted, then the first matching relation will be used.
     * Assumption: one object has relationship  (has_one, has_many or many_many ) with the other object
     *
     * @example I assign the "TaxonomyTerm" "For customers" to the "Page" "Page1" in the "Terms" relation
     * @Given /^I assign (?:an|a|the) "([^"]+)" "([^"]+)" to (?:an|a|the) "([^"]+)" "([^"]+)" in the "([^"]+)" relation$/
     */
    public function stepIAssignObjToObjInTheRelation($type, $value, $relationType, $relationId, $relationName)
    {
        $class = $this->convertTypeToClass($type);
        $relationClass = $this->convertTypeToClass($relationType);

        // Check if this fixture object already exists - if not, we create it
        $relationObj = $this->fixtureFactory->get($relationClass, $relationId);
        if (!$relationObj) {
            $relationObj = $this->fixtureFactory->createObject($relationClass, $relationId);
        }

        // Check if there is relationship defined in many_many (includes belongs_many_many)
        $manyField = null;
        $oneField = null;
        if ($relationObj->many_many()) {
            $manyField = array_search($class, $relationObj->many_many());
            if ($manyField && strlen($relationName) > 0) {
                $manyField = $relationName;
            }
        }
        if (empty($manyField) && $relationObj->has_many()) {
            $manyField = array_search($class, $relationObj->has_many());
            if ($manyField && strlen($relationName) > 0) {
                $manyField = $relationName;
            }
        }
        if (empty($manyField) && $relationObj->has_one()) {
            $oneField = array_search($class, $relationObj->has_one());
            if ($oneField && strlen($relationName) > 0) {
                $oneField = $relationName;
            }
        }
        if (empty($manyField) && empty($oneField)) {
            throw new \Exception("'$relationClass' has no relationship (has_one, has_many and many_many) with '$class'!");
        }

        // Get the searchable field to check if the fixture object already exists
        $temObj = new $class;
        if (isset($temObj->Name)) {
            $field = "Name";
        } elseif (isset($temObj->Title)) {
            $field = "Title";
        } else {
            $field = "ID";
        }

        // Check if the fixture object exists - if not, we create it
        $obj = DataObject::get($class)->filter($field, $value)->first();
        if (!$obj) {
            $obj = $this->fixtureFactory->createObject($class, $value);
        }
        // If has_many or many_many, add this fixture object to the relation object
        // If has_one, set value to the joint field with this fixture object's ID
        if ($manyField) {
            $relationObj->$manyField()->add($obj);
        } elseif ($oneField) {
            // E.g. $has_one = array('PanelOffer' => 'Offer');
            // then the join field is PanelOfferID. This is the common rule in the CMS
            $relationObj->{$oneField . 'ID'} = $obj->ID;
        }

        $relationObj->write();
    }

     /**
     * Example: Given the "page" "Page 1" is not published
     *
     * @Given /^(?:an|a|the) "([^"]+)" "([^"]+)" is ([^"]*)$/
     */
    public function stepUpdateRecordState($type, $id, $state)
    {
        $class = $this->convertTypeToClass($type);
        /** @var DataObject|Versioned $obj */
        $obj = $this->fixtureFactory->get($class, $id);
        if (!$obj) {
            throw new \InvalidArgumentException(sprintf(
                'Can not find record "%s" with identifier "%s"',
                $type,
                $id
            ));
        }

        switch ($state) {
            case 'published':
                $obj->copyVersionToStage('Stage', 'Live');
                break;
            case 'not published':
            case 'unpublished':
                $oldMode = Versioned::get_reading_mode();
                Versioned::set_stage(Versioned::LIVE);
                $clone = clone $obj;
                $clone->delete();
                Versioned::set_reading_mode($oldMode);
                break;
            case 'deleted':
                $obj->delete();
                break;
            default:
                throw new \InvalidArgumentException(sprintf(
                    'Invalid state: "%s"',
                    $state
                ));
        }
    }

    /**
     * Accepts YAML fixture definitions similar to the ones used in SilverStripe unit testing.
     *
     * Example: Given there are the following member records:
     *  member1:
     *    Email: member1@test.com
     *  member2:
     *    Email: member2@test.com
     *
     * @Given /^there are the following ([^\s]*) records$/
     */
    public function stepThereAreTheFollowingRecords($dataObject, PyStringNode $string)
    {
        $yaml = array_merge(array($dataObject . ':'), $string->getLines());
        $yaml = implode("\n  ", $yaml);

        // Save fixtures into database
        // TODO Run prepareAsset() for each File and Folder record
        $yamlFixture = new YamlFixture($yaml);
        $yamlFixture->writeInto($this->getFixtureFactory());
    }

    /**
     * Example: Given a "member" "Admin" belonging to "Admin Group"
     *
     * @Given /^(?:an|a|the) "member" "([^"]+)" belonging to "([^"]+)"$/
     */
    public function stepCreateMemberWithGroup($id, $groupId)
    {
        $group = $this->fixtureFactory->get('SilverStripe\\Security\\Group', $groupId);
        if (!$group) {
            $group = $this->fixtureFactory->createObject('SilverStripe\\Security\\Group', $groupId);
        }

        $member = $this->fixtureFactory->createObject('SilverStripe\\Security\\Member', $id);
        $member->Groups()->add($group);
    }

    /**
     * Example: Given a "member" "Admin" belonging to "Admin Group" with "Email"="test@test.com"
     *
     * @Given /^(?:an|a|the) "member" "([^"]+)" belonging to "([^"]+)" with (.*)$/
     */
    public function stepCreateMemberWithGroupAndData($id, $groupId, $data)
    {
        $class = 'SilverStripe\\Security\\Member';
        preg_match_all(
            '/"(?<key>[^"]+)"\s*=\s*"(?<value>[^"]+)"/',
            $data,
            $matches
        );
        $fields = $this->convertFields(
            $class,
            array_combine($matches['key'], $matches['value'])
        );

        $group = $this->fixtureFactory->get('SilverStripe\\Security\\Group', $groupId);
        if (!$group) {
            $group = $this->fixtureFactory->createObject('SilverStripe\\Security\\Group', $groupId);
        }

        $member = $this->fixtureFactory->createObject($class, $id, $fields);
        $member->Groups()->add($group);
    }

    /**
     * Example: Given a "group" "Admin" with permissions "Access to 'Pages' section" and "Access to 'Files' section"
     *
     * @Given /^(?:an|a|the) "group" "([^"]+)" (?:with|has) permissions (.*)$/
     */
    public function stepCreateGroupWithPermissions($id, $permissionStr)
    {
        // Convert natural language permissions to codes
        preg_match_all('/"([^"]+)"/', $permissionStr, $matches);
        $permissions = $matches[1];
        $codes = Permission::get_codes(false);

        $group = $this->fixtureFactory->get('SilverStripe\\Security\\Group', $id);
        if (!$group) {
            $group = $this->fixtureFactory->createObject('SilverStripe\\Security\\Group', $id);
        }

        foreach ($permissions as $permission) {
            $found = false;
            foreach ($codes as $code => $details) {
                if ($permission == $code
                    || $permission == $details['name']
                ) {
                    Permission::grant($group->ID, $code);
                    $found = true;
                }
            }
            if (!$found) {
                throw new \InvalidArgumentException(sprintf(
                    'No permission found for "%s"',
                    $permission
                ));
            }
        }
    }

    /**
     * Navigates to a record based on its identifier set during fixture creation,
     * using its RelativeLink() method to map the record to a URL.
     * Example: Given I go to the "page" "My Page"
     *
     * @Given /^I go to (?:an|a|the) "([^"]+)" "([^"]+)"/
     */
    public function stepGoToNamedRecord($type, $id)
    {
        $class = $this->convertTypeToClass($type);
        $record = $this->fixtureFactory->get($class, $id);
        if (!$record) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot resolve reference "%s", no matching fixture found',
                $id
            ));
        }
        if (!$record->hasMethod('RelativeLink')) {
            throw new \InvalidArgumentException('URL for record cannot be determined, missing RelativeLink() method');
        }

        $this->getSession()->visit($this->getMainContext()->locatePath($record->RelativeLink()));
    }


    /**
     * Checks that a file or folder exists in the webroot.
     * Example: There should be a file "assets/Uploads/test.jpg"
     *
     * @Then /^there should be a ((file|folder) )"([^"]*)"/
     */
    public function stepThereShouldBeAFileOrFolder($type, $path)
    {
        assertFileExists($this->joinPaths(BASE_PATH, $path));
    }

    /**
     * Checks that a file exists in the asset store with a given filename and hash
     *
     * Example: there should be a filename "Uploads/test.jpg" with hash "59de0c841f"
     *
     * @Then /^there should be a filename "([^"]*)" with hash "([a-fA-Z0-9]+)"/
     */
    public function stepThereShouldBeAFileWithTuple($filename, $hash)
    {
        $exists = $this->getAssetStore()->exists($filename, $hash);
        assertTrue((bool)$exists, "A file exists with filename $filename and hash $hash");
    }

    /**
     * Replaces fixture references in values with their respective database IDs,
     * with the notation "=><class>.<identifier>". Example: "=>Page.My Page".
     *
     * @Transform /^([^"]+)$/
     */
    public function lookupFixtureReference($string)
    {
        if (preg_match('/^=>/', $string)) {
            list($className, $identifier) = explode('.', preg_replace('/^=>/', '', $string), 2);
            $id = $this->fixtureFactory->getId($className, $identifier);
            if (!$id) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot resolve reference "%s", no matching fixture found',
                    $string
                ));
            }
            return $id;
        } else {
            return $string;
        }
    }

    /**
     * @Given /^(?:an|a|the) "([^"]*)" "([^"]*)" was (created|last edited) "([^"]*)"$/
     */
    public function aRecordWasLastEditedRelative($type, $id, $mod, $time)
    {
        $class = $this->convertTypeToClass($type);
        $fields = $this->prepareFixture($class, $id);
        $record = $this->fixtureFactory->createObject($class, $id, $fields);
        $date = date("Y-m-d H:i:s", strtotime($time));
        $table = $record->baseTable();
        $field = ($mod == 'created') ? 'Created' : 'LastEdited';
        DB::prepared_query(
            "UPDATE \"{$table}\" SET \"{$field}\" = ? WHERE \"ID\" = ?",
            [$date, $record->ID]
        );
        // Support for Versioned extension, by checking for a "Live" stage
        if (DB::get_schema()->hasTable($table . '_Live')) {
            DB::prepared_query(
                "UPDATE \"{$table}_Live\" SET \"{$field}\" = ? WHERE \"ID\" = ?",
                [$date, $record->ID]
            );
        }
    }

    /**
     * Prepares a fixture for use
     *
     * @param string $class
     * @param string $identifier
     * @param array $data
     * @return array Prepared $data with additional injected fields
     */
    protected function prepareFixture($class, $identifier, $data = array())
    {
        if ($class == 'SilverStripe\\Assets\\File' || is_subclass_of($class, 'SilverStripe\\Assets\\File')) {
            $data =  $this->prepareAsset($class, $identifier, $data);
        }
        return $data;
    }

    protected function prepareAsset($class, $identifier, $data = null)
    {
        if (!$data) {
            $data = array();
        }


        $relativeTargetPath = (isset($data['Filename'])) ? $data['Filename'] : $identifier;
        $relativeTargetPath = preg_replace('/^' . ASSETS_DIR . '\/?/', '', $relativeTargetPath);
        $sourcePath = $this->joinPaths($this->getFilesPath(), basename($relativeTargetPath));

        // Create file or folder on filesystem
        if ($class == 'SilverStripe\\Assets\\Folder' || is_subclass_of($class, 'SilverStripe\\Assets\\Folder')) {
            $parent = Folder::find_or_make($relativeTargetPath);
            $data['ID'] = $parent->ID;
        } else {
            // Check file exists
            if (!file_exists($sourcePath)) {
                throw new \InvalidArgumentException(sprintf(
                    'Source file for "%s" cannot be found in "%s"',
                    $relativeTargetPath,
                    $sourcePath
                ));
            }

            // Get parent
            $parentID = 0;
            if (strstr($relativeTargetPath, '/')) {
                $folderName = dirname($relativeTargetPath);
                $parent = Folder::find_or_make($folderName);
                if ($parent) {
                    $parentID = $parent->ID;
                }
            }
            $data['ParentID'] = $parentID;

            // Load file into APL and retrieve tuple
            $asset = $this->getAssetStore()->setFromLocalFile(
                $sourcePath,
                $relativeTargetPath,
                null,
                null,
                array(
                    'conflict' => AssetStore::CONFLICT_OVERWRITE,
                    'visibility' => AssetStore::VISIBILITY_PUBLIC
                )
            );
            $data['FileFilename'] = $asset['Filename'];
            $data['FileHash'] = $asset['Hash'];
            $data['FileVariant'] = $asset['Variant'];
        }
        if (!isset($data['Name'])) {
            $data['Name'] = basename($relativeTargetPath);
        }

        // Save assets
        if (isset($data['FileFilename'])) {
            $this->createdAssets[] = $data;
        }

        return $data;
    }

    /**
     *
     * @return AssetStore
     */
    protected function getAssetStore()
    {
        return singleton('AssetStore');
    }

    /**
     * Converts a natural language class description to an actual class name.
     * Respects {@link DataObject::$singular_name} variations.
     * Example: "redirector page" -> "RedirectorPage"
     *
     * @param String
     * @return String Class name
     */
    protected function convertTypeToClass($type)
    {
        $type = trim($type);

        // Try direct mapping
        $class = str_replace(' ', '', ucwords($type));
        if (class_exists($class) && is_subclass_of($class, 'SilverStripe\\ORM\\DataObject')) {
            return ClassInfo::class_name($class);
        }

        // Fall back to singular names
        foreach (array_values(ClassInfo::subclassesFor('SilverStripe\\ORM\\DataObject')) as $candidate) {
            if (strcasecmp(singleton($candidate)->singular_name(), $type) === 0) {
                return $candidate;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Class "%s" does not exist, or is not a subclass of DataObjet',
            $class
        ));
    }

    /**
     * Updates an object with values, resolving aliases set through
     * {@link DataObject->fieldLabels()}.
     *
     * @param string $class Class name
     * @param array $fields Map of field names or aliases to their values.
     * @return array Map of actual object properties to their values.
     */
    protected function convertFields($class, $fields)
    {
        $labels = singleton($class)->fieldLabels();
        foreach ($fields as $fieldName => $fieldVal) {
            if ($fieldLabelKey = array_search($fieldName, $labels)) {
                unset($fields[$fieldName]);
                $fields[$labels[$fieldLabelKey]] = $fieldVal;
            }
        }
        return $fields;
    }

    protected function joinPaths()
    {
        $args = func_get_args();
        $paths = array();
        foreach ($args as $arg) {
            $paths = array_merge($paths, (array)$arg);
        }
        foreach ($paths as &$path) {
            $path = trim($path, '/');
        }
        if (substr($args[0], 0, 1) == '/') {
            $paths[0] = '/' . $paths[0];
        }
        return join('/', $paths);
    }
}
