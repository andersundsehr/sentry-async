<?php

namespace AUS\SentryAsync\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * @return \Symfony\Component\Config\Definition\Builder\TreeBuilder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('sentry_async');
        $rootNode = method_exists(TreeBuilder::class, 'getRootNode') ? $treeBuilder->getRootNode() : $treeBuilder->root('sentry_async');

        $rootNode
            ->children()
                ->scalarNode('compress')->defaultTrue()->end()
                ->scalarNode('limit')->defaultValue(100)->end()
                ->scalarNode('directory')->defaultValue(sys_get_temp_dir())->end()
            ->end();

        return $treeBuilder;
    }
}
