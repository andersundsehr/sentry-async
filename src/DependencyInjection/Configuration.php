<?php

namespace AUS\SentryAsync\DependencyInjection;

use AUS\SentryAsync\Entry\Entry;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sentry_async');

        $sentryAsyncNode = $treeBuilder->getRootNode();

        $sentryAsyncNode
            ->children()
            ->arrayNode('entry_factory')
            ->children()
            ->scalarNode('entry_class')->defaultValue(Entry::class)->end()
            ->end()
            ->end()
            ->arrayNode('file_queue')
            ->children()
            ->scalarNode('compress')->defaultTrue()->end()
            ->integerNode('limit')->defaultValue(100)->end()
            ->scalarNode('directory')->defaultValue(sys_get_temp_dir())->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
