<?php

namespace Packagist\WebBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('packagist_web');

        $rootNode
            ->children()
                ->scalarNode('rss_max_items')->defaultValue(40)->end()
                ->booleanNode('archive')
                    ->defaultFalse()
                ->end()
                ->arrayNode('archive_options')
                    ->children()
                        ->scalarNode('format')->defaultValue('zip')->end()
                        ->scalarNode('basedir')->cannotBeEmpty()->end()
                        ->scalarNode('endpoint')->cannotBeEmpty()->end()
                        ->scalarNode('cache_duration')->defaultValue(30 * 60)->end()
                        ->booleanNode('include_archive_checksum')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
