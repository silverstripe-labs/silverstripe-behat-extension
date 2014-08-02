<?php

namespace SilverStripe\BehatExtension\Controllers;

use InvalidArgumentException;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\Core\Manifest\ModuleLoader;

trait ModuleCommandTrait
{
    /**
     * Find target module being tested
     *
     * @param string $name
     * @return Module
     */
    protected function getModule($name)
    {
        if (strpos($name, '@') === 0) {
            $name = substr($name, 1);
        }
        $module = ModuleLoader::instance()->getManifest()->getModule($name);
        if (!$module) {
            throw new InvalidArgumentException("No module $name installed");
        }
        return $module;
    }
}
