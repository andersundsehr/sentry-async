<?php

declare(strict_types=1);

namespace AUS\SentryAsync\DependencyInjection;

use AUS\SentryAsync\Queue\FileQueue;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

final class SentryAsyncExtension extends ConfigurableExtension
{
    /**
     * @throws \Exception
     */
    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        $container
            ->register(FileQueue::class, FileQueue::class)
            ->setPublic(false)
            ->setArgument(0, $mergedConfig['limit'])
            ->setArgument(1, $mergedConfig['compress'])
            ->setArgument(2, $mergedConfig['directory']);
    }
}
