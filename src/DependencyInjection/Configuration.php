<?php

declare(strict_types=1);

namespace AUS\SentryAsync\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use LogicException;
use AUS\SentryAsync\Entry\Entry;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sentry_async');

        $sentryAsyncNode = $treeBuilder->getRootNode();
        if (!$sentryAsyncNode instanceof ArrayNodeDefinition) {
            throw new LogicException('Expected an array configuration root', 1791890009);
        }

        $children = $sentryAsyncNode->children();
        $entryFactory = $children->arrayNode('entry_factory')->addDefaultsIfNotSet()->children();
        $entryFactory->scalarNode('entry_class')->cannotBeEmpty()->defaultValue(Entry::class);

        $fileQueue = $children->arrayNode('file_queue')->addDefaultsIfNotSet()->children();
        $fileQueue->booleanNode('compress')->defaultTrue();
        $fileQueue->integerNode('limit')->min(0)->defaultValue(100);
        $fileQueue->scalarNode('directory')->cannotBeEmpty()->defaultValue(sys_get_temp_dir());

        return $treeBuilder;
    }
}
