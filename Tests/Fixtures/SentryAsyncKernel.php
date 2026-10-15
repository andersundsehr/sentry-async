<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Tests\Fixtures;

use AUS\SentryAsync\SentryAsyncBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

final class SentryAsyncKernel extends Kernel
{
    public function __construct(private readonly string $projectDirectory)
    {
        parent::__construct('test_' . hash('sha256', $projectDirectory), true);
    }

    public function registerBundles(): iterable
    {
        yield new SentryAsyncBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load($this->projectDirectory . '/config.yaml');
    }

    public function getProjectDir(): string
    {
        return $this->projectDirectory;
    }

    public function getCacheDir(): string
    {
        return $this->projectDirectory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->projectDirectory . '/log';
    }
}
