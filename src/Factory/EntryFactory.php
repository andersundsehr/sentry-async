<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Factory;

use ReflectionClass;
use InvalidArgumentException;
use AUS\SentryAsync\Entry\EntryInterface;
use ReflectionException;

readonly class EntryFactory
{
    /** @var class-string<EntryInterface> */
    private string $entryClass;

    /**
     * @throws ReflectionException
     */
    public function __construct(string $entryClass)
    {
        if (!is_a($entryClass, EntryInterface::class, true) || !(new ReflectionClass($entryClass))->isInstantiable()) {
            throw new InvalidArgumentException(
                'Entry class must implement EntryInterface and be instantiable',
                1791890008
            );
        }

        $this->entryClass = $entryClass;
    }

    public function createEntry(string $payload): EntryInterface
    {
        return new $this->entryClass($payload);
    }
}
