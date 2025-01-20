<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Queue;

use AUS\SentryAsync\Entry\EntryInterface;
use AUS\SentryAsync\Factory\EntryFactory;
use Exception;
use FilesystemIterator;
use RuntimeException;

class FileQueue implements QueueInterface
{
    private ?FilesystemIterator $filesystemIterator = null;

    public function __construct(
        private readonly int $limit,
        private readonly bool $compress,
        private string $directory,
        private readonly EntryFactory $entryFactory
    ) {
        if (!str_ends_with($this->directory, DIRECTORY_SEPARATOR)) {
            $this->directory .= DIRECTORY_SEPARATOR;
        }

        if (!file_exists($this->directory)) {
            try {
                if (!mkdir($concurrentDirectory = $this->directory, 0777, true) && !is_dir($concurrentDirectory)) {
                    throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                }
            } catch (Exception) {
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function pop(string &$identifier): ?EntryInterface
    {
        if (null === $this->filesystemIterator) {
            $this->filesystemIterator = new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME);
        }

        do {
            if (!$this->filesystemIterator->valid()) {
                return null;
            }

            $file = $this->filesystemIterator->current();
            $this->filesystemIterator->next();
            assert(is_string($file));
        } while (!str_ends_with($file, '.entry'));

        $fp = fopen($file, 'rb');
        if (false === $fp) {
            return null;
        }

        $mime = mime_content_type($file);
        switch ($mime) {
            case 'application/json':
                break;
            case 'application/octet-stream':
                @stream_filter_append($fp, 'zlib.inflate', STREAM_FILTER_READ);
                break;
            default:
        }

        $content = stream_get_contents($fp);
        fclose($fp);

        if (!$content) {
            return null;
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!$data) {
            return null;
        }

        if (!isset($data['payload'])) {
            return null;
        }

        $identifier = basename($file);
        return $this->entryFactory->createEntry($data['payload']);
    }

    /**
     * @inheritdoc
     */
    public function push(EntryInterface $entry): ?string
    {
        if ($this->limit) {
            $fileCount = iterator_count(new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS));
            if ($fileCount > $this->limit) {
                return null;
            }
        }

        $fileName = $this->directory . microtime(true) . sha1($entry->getPayload()) . '.entry';

        /** @noinspection JsonEncodingApiUsageInspection */
        $data = @json_encode($entry);
        if (!$data) {
            return null;
        }

        $fp = fopen($fileName, 'wb');
        if (!$fp) {
            return null;
        }

        if ($this->compress) {
            @stream_filter_append($fp, 'zlib.deflate', STREAM_FILTER_WRITE);
        }

        @fwrite($fp, $data);
        @fclose($fp);
        return $fileName;
    }

    /**
     * @inheritdoc
     */
    public function remove(string $identifier): bool
    {
        $absFile = $this->directory . $identifier;
        if (file_exists($absFile)) {
            return unlink($absFile);
        }

        return false;
    }
}
