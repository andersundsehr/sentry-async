<?php

namespace AUS\SentryAsync\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('sentry_async');

        $sentryAsyncNode = $treeBuilder->getRootNode();

        $sentryAsyncNode
            ->children()
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
