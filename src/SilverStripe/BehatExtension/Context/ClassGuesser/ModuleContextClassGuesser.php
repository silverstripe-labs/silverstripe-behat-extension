<?php

namespace SilverStripe\BehatExtension\Context\ClassGuesser;

use Behat\Behat\Context\ClassGuesser\ClassGuesserInterface;

/**
 * Module context class guesser.
 * Provides module context class if found.
 */
class ModuleContextClassGuesser implements ClassGuesserInterface
{
    private $namespaceSuffix;
    private $namespaceBase;
    private $contextClass;

    /**
     * Initializes guesser.
     *
     * @param string $namespaceSuffix
     */
    public function __construct($namespaceSuffix, $contextClass)
    {
        $this->namespaceSuffix = $namespaceSuffix;
        $this->contextClass = $contextClass;
    }

    /**
     * Sets bundle namespace to use for guessing.
     *
     * @param string $namespace
     */
    public function setNamespaceBase($namespaceBase)
    {
        $this->namespaceBase = $namespaceBase;
        return $this;
    }

    /**
     * Tries to guess context classname.
     *
     * @return string
     */
    public function guess()
    {
        // Try fully qualified namespace
        if (class_exists($class = $this->namespaceBase.'\\'.$this->namespaceSuffix.'\\'.$this->contextClass)) {
            return $class;
        }
        // Fall back to namespace with SilverStripe prefix
        // TODO Remove once core has namespace capabilities for modules
        if (class_exists($class = 'SilverStripe\\'.$this->namespaceBase.'\\'.$this->namespaceSuffix.'\\'.$this->contextClass)) {
            return $class;
        }
    }
}
