<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\ClosuredContextInterface,
	Behat\Behat\Context\TranslatedContextInterface,
	Behat\Behat\Context\BehatContext,
	Behat\Behat\Context\Step,
	Behat\Behat\Event\StepEvent,
	Behat\Behat\Event\FeatureEvent,
	Behat\Behat\Event\ScenarioEvent,
	Behat\Behat\Exception\PendingException,
	Behat\Mink\Driver\Selenium2Driver,
	Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

// PHPUnit
require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * Context used to create fixtures in the SilverStripe ORM.
 */
class FixtureContext extends BehatContext
{
	protected $context;

	/**
	 * @var \FixtureFactory
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

	public function __construct(array $parameters) {
		$this->context = $parameters;
	}

	public function getSession($name = null) {
		return $this->getMainContext()->getSession($name);
	}

	/**
	 * @return \FixtureFactory
	 */
	public function getFixtureFactory() {
		if(!$this->fixtureFactory) {
			$this->fixtureFactory = \Injector::inst()->create('FixtureFactory', 'FixtureContextFactory');
		}
		return $this->fixtureFactory;
	}

	/**
	 * @param \FixtureFactory $factory
	 */
	public function setFixtureFactory(\FixtureFactory $factory) {
		$this->fixtureFactory = $factory;
	}

	/**
	 * @param String
	 */
	public function setFilesPath($path) {
		$this->filesPath = $path;
	}

	/**
	 * @return String
	 */
	public function getFilesPath() {
		return $this->filesPath;
	}

	/**
	 * @BeforeScenario @database-defaults
	 */
	public function beforeDatabaseDefaults(ScenarioEvent $event) {
		\SapphireTest::empty_temp_db();
		\DB::getConn()->quiet();
		$dataClasses = \ClassInfo::subclassesFor('DataObject');
		array_shift($dataClasses);
		foreach ($dataClasses as $dataClass) {
			\singleton($dataClass)->requireDefaultRecords();
		}
	}

	/**
	 * @AfterScenario
	 */
	public function afterResetDatabase(ScenarioEvent $event) {
		\SapphireTest::empty_temp_db();
	}

	/**
	 * @AfterScenario
	 */
	public function afterResetAssets(ScenarioEvent $event) {
		if (is_array($this->createdFilesPaths)) {
			$createdFiles = array_reverse($this->createdFilesPaths);
			foreach ($createdFiles as $path) {
				if (is_dir($path)) {
					\Filesystem::removeFolder($path);
				} else {
					@unlink($path);
				}
			}
		}
	}

	/**
	 * Example: Given a "page" "Page 1"
	 * 
	 * @Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)"$/
	 */
	public function stepCreateRecord($type, $id) {
		$class = $this->convertTypeToClass($type);
		$fields = $this->prepareFixture($class, $id);
		$this->fixtureFactory->createObject($class, $id, $fields);
	}

	/**
	 * Example: Given a "page" "Page 1" has the "content" "My content" 
	 * 
	 * @Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" has (?:(an|a|the) )"(?<field>.*)" "(?<value>.*)"$/
	 */
	public function stepCreateRecordHasField($type, $id, $field, $value) {
		$class = $this->convertTypeToClass($type);
		$fields = $this->convertFields(
			$class,
			array($field => $value)
		);
		$this->fixtureFactory->createObject($class, $id, $fields);
	}
   
	/**
	 * Example: Given a "page" "Page 1" with "URL"="page-1" and "Content"="my page 1" 
	 * 
	 * @Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" with (?<data>.*)$/
	 */
	public function stepCreateRecordWithData($type, $id, $data) {
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
		$this->fixtureFactory->createObject($class, $id, $fields);
	}

	/**
	 * Example: And the "page" "Page 2" has the following data 
	 * | Content | <blink> |
	 * | My Property | foo |
	 * | My Boolean | bar |
	 * 
	 * @Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" has the following data$/
	 */
	public function stepCreateRecordWithTable($type, $id, $null, TableNode $fieldsTable) {
		$class = $this->convertTypeToClass($type);
		// TODO Support more than one record
		$fields = $this->convertFields($class, $fieldsTable->getRowsHash());
		$fields = $this->prepareFixture($class, $id, $fields);

		// We should check if this fixture object already exists - if it does, we update it. If not, we create it
		if($existingFixture = $this->fixtureFactory->get($class, $id)) {
			// Merge existing data with new data, and create new object to replace existing object
			foreach($fields as $k => $v) {
				$existingFixture->$k = $v;
			}
			$existingFixture->write();
		} else {
			$this->fixtureFactory->createObject($class, $id, $fields);
		}
	}

	/**
	 * Example: Given the "page" "Page 1.1" is a child of the "page" "Page1" 
	 * 
	 * @Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" is a (?<relation>[^\s]*) of (?:(an|a|the) )"(?<relationType>[^"]+)" "(?<relationId>[^"]+)"/
	 */
	public function stepUpdateRecordRelation($type, $id, $relation, $relationType, $relationId) {
		$class = $this->convertTypeToClass($type);
		$relationClass = $this->convertTypeToClass($relationType);
		
		$obj = $this->fixtureFactory->get($class, $id);
		if(!$obj) $obj = $this->fixtureFactory->createObject($class, $id);
		
		$relationObj = $this->fixtureFactory->get($relationClass, $relationId);
		if(!$relationObj) $relationObj = $this->fixtureFactory->createObject($relationClass, $relationId);
		
		switch($relation) {
			case 'parent':
				$relationObj->ParentID = $obj->ID;
				$relationObj->write();
				break;
			case 'child':
				$obj->ParentID = $relationObj->ID;
				$obj->write();
				break;
			default:
				throw new \InvalidArgumentException(sprintf(
					'Invalid relation "%s"', $relation
				));
		}
	}

  
	 /**
	 * Example: Given the "page" "Page 1" is not published 
	 * 
	 * @Given /^(?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)" is (?<state>[^"]*)$/
	 */
	public function stepUpdateRecordState($type, $id, $state) {
		$class = $this->convertTypeToClass($type);
		$obj = $this->fixtureFactory->get($class, $id);
		if(!$obj) {
			throw new \InvalidArgumentException(sprintf(
				'Can not find record "%s" with identifier "%s"',
				$type,
				$id
			));
		}

		switch($state) {
			case 'published':
				$obj->publish('Stage', 'Live');
				break;
			case 'not published':
			case 'unpublished':
				$oldMode = \Versioned::get_reading_mode();
				\Versioned::reading_stage('Live');
				$clone = clone $obj;
				$clone->delete();
				\Versioned::reading_stage($oldMode);
				break;
			case 'deleted':
				$obj->delete();
				break;
			default:
				throw new \InvalidArgumentException(sprintf(
					'Invalid state: "%s"', $state
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
	public function stepThereAreTheFollowingRecords($dataObject, PyStringNode $string) {
		$yaml = array_merge(array($dataObject . ':'), $string->getLines());
		$yaml = implode("\n  ", $yaml);

		// Save fixtures into database
		// TODO Run prepareAsset() for each File and Folder record
		$yamlFixture = new \YamlFixture($yaml);
		$yamlFixture->writeInto($this->getFixtureFactory());
	}

	/**
	 * Example: Given a "member" "Admin" belonging to "Admin Group"
	 * 
	 * @Given /^(?:(an|a|the) )"member" "(?<id>[^"]+)" belonging to "(?<groupId>[^"]+)"$/
	 */
	public function stepCreateMemberWithGroup($id, $groupId) {
		$group = $this->fixtureFactory->get('Group', $groupId);
		if(!$group) $group = $this->fixtureFactory->createObject('Group', $groupId);
		
		$member = $this->fixtureFactory->createObject('Member', $id);
		$member->Groups()->add($group);
	}

	/**
	 * Example: Given a "member" "Admin" belonging to "Admin Group" with "Email"="test@test.com"
	 * 
	 * @Given /^(?:(an|a|the) )"member" "(?<id>[^"]+)" belonging to "(?<groupId>[^"]+)" with (?<data>.*)$/
	 */
	public function stepCreateMemberWithGroupAndData($id, $groupId, $data) {
		$class = 'Member';
		preg_match_all(
			'/"(?<key>[^"]+)"\s*=\s*"(?<value>[^"]+)"/', 
			$data,
			$matches
		);
		$fields = $this->convertFields(
			$class,
			array_combine($matches['key'], $matches['value'])
		);
		
		$group = $this->fixtureFactory->get('Group', $groupId);
		if(!$group) $group = $this->fixtureFactory->createObject('Group', $groupId);

		$member = $this->fixtureFactory->createObject($class, $id, $fields);
		$member->Groups()->add($group);
	}

	/**
	 * Example: Given a "group" "Admin" with permissions "Access to 'Pages' section" and "Access to 'Files' section"
	 * 
	 * @Given /^(?:(an|a|the) )"group" "(?<id>[^"]+)" (?:(with|has)) permissions (?<permissionStr>.*)$/
	 */
	public function stepCreateGroupWithPermissions($id, $permissionStr) {
		// Convert natural language permissions to codes
		preg_match_all('/"([^"]+)"/', $permissionStr, $matches);
		$permissions = $matches[1];
		$codes = \Permission::get_codes(false);

		$group = $this->fixtureFactory->get('Group', $id);
		if(!$group) $group = $this->fixtureFactory->createObject('Group', $id);
		
		foreach($permissions as $permission) {
			$found = false;
			foreach($codes as $code => $details) {
				if(
					$permission == $code
					|| $permission == $details['name']
				) {
					\Permission::grant($group->ID, $code);
					$found = true;
				}
			}
			if(!$found) {
				throw new \InvalidArgumentException(sprintf(
					'No permission found for "%s"', $permission
				));    
			}
		}
	}

	/**
	 * Navigates to a record based on its identifier set during fixture creation,
	 * using its RelativeLink() method to map the record to a URL.
	 * Example: Given I go to the "page" "My Page"
	 * 
	 * @Given /^I go to (?:(an|a|the) )"(?<type>[^"]+)" "(?<id>[^"]+)"/
	 */
	public function stepGoToNamedRecord($type, $id) {
		$class = $this->convertTypeToClass($type);
		$record = $this->fixtureFactory->get($class, $id);
		if(!$record) {
			throw new \InvalidArgumentException(sprintf(
				'Cannot resolve reference "%s", no matching fixture found',
				$id
			));
		}
		if(!$record->hasMethod('RelativeLink')) {
			throw new \InvalidArgumentException('URL for record cannot be determined, missing RelativeLink() method');
		}

		$this->getSession()->visit($this->getMainContext()->locatePath($record->RelativeLink()));
	}


	/**
	 * Checks that a file or folder exists in the webroot.
	 * Example: There should be a file "assets/Uploads/test.jpg"
	 * 
	 * @Then /^there should be a (?<type>(file|folder) )"(?<path>[^"]*)"/
	 */
	public function stepThereShouldBeAFileOrFolder($type, $path) {
		assertFileExists($this->joinPaths(BASE_PATH, $path));
	}

	/**
	 * Replaces fixture references in values with their respective database IDs, 
	 * with the notation "=><class>.<identifier>". Example: "=>Page.My Page".
	 * 
	 * @Transform /^([^"]+)$/
	 */
	public function lookupFixtureReference($string) {
		if(preg_match('/^=>/', $string)) {
			list($className, $identifier) = explode('.', preg_replace('/^=>/', '', $string), 2);
			$id = $this->fixtureFactory->getId($className, $identifier);
			if(!$id) {
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
	 * @Given /^(?:(an|a|the) )"(?<type>[^"]*)" "(?<id>[^"]*)" was (?<mod>(created|last edited)) "(?<time>[^"]*)"$/
	 */
	public function aRecordWasLastEditedRelative($type, $id, $mod, $time) {
		$class = $this->convertTypeToClass($type);
		$fields = $this->prepareFixture($class, $id);
		$record = $this->fixtureFactory->createObject($class, $id, $fields);
		$date = date("Y-m-d H:i:s",strtotime($time));
		$table = \ClassInfo::baseDataClass(get_class($record));
		$field = ($mod == 'created') ? 'Created' : 'LastEdited';
		\DB::query(sprintf(
			'UPDATE "%s" SET "%s" = \'%s\' WHERE "ID" = \'%d\'',
			$table,
			$field,
			$date,
			$record->ID
		)); 
		// Support for Versioned extension, by checking for a "Live" stage
		if(\DB::getConn()->hasTable($table . '_Live')) {
			\DB::query(sprintf(
				'UPDATE "%s_Live" SET "%s" = \'%s\' WHERE "ID" = \'%d\'',
				$table,
				$field,
				$date,
				$record->ID
			)); 
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
	protected function prepareFixture($class, $identifier, $data = array()) {
		if($class == 'File' || is_subclass_of($class, 'File')) {
			$data =  $this->prepareAsset($class, $identifier, $data);
		}
		return $data;
	}

	protected function prepareAsset($class, $identifier, $data = null) {
		if(!$data) $data = array();
		$relativeTargetPath = (isset($data['Filename'])) ? $data['Filename'] : $identifier;
		$relativeTargetPath = preg_replace('/^' . ASSETS_DIR . '/', '', $relativeTargetPath);
		$targetPath = $this->joinPaths(ASSETS_PATH, $relativeTargetPath);
		$sourcePath = $this->joinPaths($this->getFilesPath(), basename($relativeTargetPath));
		
		// Create file or folder on filesystem
		if($class == 'Folder' || is_subclass_of($class, 'Folder')) {
			$parent = \Folder::find_or_make($relativeTargetPath);
		} else {
			if(!file_exists($sourcePath)) {
				throw new \InvalidArgumentException(sprintf(
					'Source file for "%s" cannot be found in "%s"',
					$targetPath,
					$sourcePath
				));
			}
			$parent = \Folder::find_or_make(dirname($relativeTargetPath));
			copy($sourcePath, $targetPath);
		}
		$data['Filename'] = $this->joinPaths(ASSETS_DIR, $relativeTargetPath);
		if(!isset($data['Name'])) $data['Name'] = basename($relativeTargetPath);
		if($parent) $data['ParentID'] = $parent->ID;

		$this->createdFilesPaths[] = $targetPath;

		return $data;
	}

	/**
	 * Converts a natural language class description to an actual class name.
	 * Respects {@link DataObject::$singular_name} variations.
	 * Example: "redirector page" -> "RedirectorPage"
	 * 
	 * @param String 
	 * @return String Class name
	 */
	protected function convertTypeToClass($type)  {
		$type = trim($type);

		// Try direct mapping
		$class = str_replace(' ', '', ucwords($type));
		if(class_exists($class) || !($class == 'DataObject' || is_subclass_of($class, 'DataObject'))) {
			return $class;
		}

		// Fall back to singular names
		foreach(array_values(\ClassInfo::subclassesFor('DataObject')) as $candidate) {
			if(singleton($candidate)->singular_name() == $type) return $candidate;
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
	 * @param String Class name
	 * @param Array Map of field names or aliases to their values.
	 * @return Array Map of actual object properties to their values.
	 */
	protected function convertFields($class, $fields) {
		$labels = singleton($class)->fieldLabels();
		foreach($fields as $fieldName => $fieldVal) {
			if($fieldLabelKey = array_search($fieldName, $labels)) {
				unset($fields[$fieldName]);
				$fields[$labels[$fieldLabelKey]] = $fieldVal;
				
			}
		}
		return $fields;
	}

	protected function joinPaths() {
		$args = func_get_args();
		$paths = array();
		foreach($args as $arg) $paths = array_merge($paths, (array)$arg);
		foreach($paths as &$path) $path = trim($path, '/');
		if (substr($args[0], 0, 1) == '/') $paths[0] = '/' . $paths[0];
		return join('/', $paths);
	}

	/**
	 * @example I assign the tags "For home;For business" to the "Page" "Page1"
	 * @example I assign the tag "For home" to the "Page" "Page1"
	 * @Given /^I assign the tag(?:|s) "([^"]*)" to the "(?<objType>[^"]+)" "(?<objId>[^"]+)"/
	 */
	public function stepIAssignTagsTo($tags, $objType, $objId) {
		$class = "TaxonomyTerm";
		$relationClass = $this->convertTypeToClass($objType);

		// Check if this fixture object already exists - if not, we create it
		$relationObj = $this->fixtureFactory->get($relationClass, $objId);
		if(!$relationObj) $relationObj = $this->fixtureFactory->createObject($relationClass, $objId);

		// Split tags
		$objTags = explode(";", $tags);
		foreach($objTags as $tag) {
			// Check if the tag exists - if not then create it
			$obj = $this->fixtureFactory->get($class, $tag);
			if(!$obj) $obj = $this->fixtureFactory->createObject($class, $tag);
			// Add tag to the destination object
			$relationObj->Tags()->add($obj);
		}

		$relationObj->write();
	}
}