<?php

declare(strict_types=1);

namespace AUS\SentryAsync\DependencyInjection;

use AUS\SentryAsync\Entry\Entry;
use AUS\SentryAsync\Factory\EntryFactory;
use Exception;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use AUS\SentryAsync\Queue\FileQueue;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class SentryAsyncExtension extends ConfigurableExtension
{
    /**
     * @param array<string, array<mixed>> $mergedConfig
     * @throws Exception
     */
    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        $container
            ->register(EntryFactory::class, EntryFactory::class)
            ->setPublic(false)
            ->setArgument(0, $mergedConfig['entry_factory']['entry_class']);

        if (isset($mergedConfig['file_queue'])) {
            $container
                ->register(FileQueue::class, FileQueue::class)
                ->setPublic(false)
                ->setArgument(0, $mergedConfig['file_queue']['limit'])
                ->setArgument(1, $mergedConfig['file_queue']['compress'])
                ->setArgument(2, $mergedConfig['file_queue']['directory'])
                ->setArgument(3, $container->getDefinition(EntryFactory::class));
        }
    }
}
