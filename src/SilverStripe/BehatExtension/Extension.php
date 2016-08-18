<?php

namespace SilverStripe\BehatExtension;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

use Behat\Behat\Extension\ExtensionInterface;

/*
 * This file is part of the SilverStripe\BehatExtension
 *
 * (c) Michał Ochman <ochman.d.michal@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * SilverStripe extension for Behat class.
 *
 * @author Michał Ochman <ochman.d.michal@gmail.com>
 */
class Extension implements ExtensionInterface
{
    /**
     * Loads a specific configuration.
     *
     * @param array            $config    Extension configuration hash (from behat.yml)
     * @param ContainerBuilder $container ContainerBuilder instance
     */
    public function load(array $config, ContainerBuilder $container)
    {
        if (!isset($config['framework_path'])) {
            throw new \InvalidArgumentException('Specify `framework_path` parameter for silverstripe_extension');
        }

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/services'));
        $loader->load('silverstripe.yml');

        $behatBasePath = $container->getParameter('behat.paths.base');
        $config['framework_path'] = realpath(sprintf(
            '%s%s%s',
            rtrim($behatBasePath, DIRECTORY_SEPARATOR),
            DIRECTORY_SEPARATOR,
            ltrim($config['framework_path'], DIRECTORY_SEPARATOR)
        ));
        if (!file_exists($config['framework_path']) || !is_dir($config['framework_path'])) {
            throw new \InvalidArgumentException('Path specified as `framework_path` either doesn\'t exist or is not a directory');
        }

        $container->setParameter('behat.silverstripe_extension.framework_path', $config['framework_path']);
        $container->setParameter('behat.silverstripe_extension.admin_url', $config['admin_url']);
        $container->setParameter('behat.silverstripe_extension.login_url', $config['login_url']);
        $container->setParameter('behat.silverstripe_extension.screenshot_path', $config['screenshot_path']);
        $container->setParameter('behat.silverstripe_extension.ajax_timeout', $config['ajax_timeout']);
        if (isset($config['ajax_steps'])) {
            $container->setParameter('behat.silverstripe_extension.ajax_steps', $config['ajax_steps']);
        }
        if (isset($config['region_map'])) {
            $container->setParameter('behat.silverstripe_extension.region_map', $config['region_map']);
        }
    }

    /**
     * @return array
     */
    public function getCompilerPasses()
    {
        return array(
            new Compiler\CoreInitializationPass()
        );
    }

    /**
     * Setups configuration for current extension.
     *
     * @param ArrayNodeDefinition $builder
     */
    public function getConfig(ArrayNodeDefinition $builder)
    {
        $builder->
            children()->
                scalarNode('framework_path')->
                    defaultValue('framework')->
                end()->
                scalarNode('screenshot_path')->
                    defaultNull()->
                end()->
                arrayNode('region_map')->
                    useAttributeAsKey('key')->
                    prototype('variable')->end()->
                end()->
                scalarNode('admin_url')->
                    defaultValue('/admin/')->
                end()->
                scalarNode('login_url')->
                    defaultValue('/Security/login')->
                end()->
                scalarNode('ajax_timeout')->
                    defaultValue(5000)->
                end()->
                arrayNode('ajax_steps')->
                    defaultValue(array(
                        'go to',
                        'follow',
                        'press',
                        'click',
                        'submit'
                    ))->
                    prototype('scalar')->
                end()->
            end()->
        end();
    }
}
