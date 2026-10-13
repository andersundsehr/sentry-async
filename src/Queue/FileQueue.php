<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Queue;

use stdClass;
use SplFileInfo;
use AUS\SentryAsync\Entry\EntryInterface;
use AUS\SentryAsync\Factory\EntryFactory;
use FilesystemIterator;
use InvalidArgumentException;
use JsonException;
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
        if ($limit < 0 || $directory === '') {
            throw new InvalidArgumentException(
                'Queue limit must be non-negative and directory must not be empty',
                1791890001
            );
        }

        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_dir($this->directory)) {
            // Storage failures must not prevent the application's container from booting.
            @mkdir($this->directory, 0777, true);
        }
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function count(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $count = 0;
        /** @var SplFileInfo $file */
        foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file->isLink() && $file->isFile() && str_ends_with($file->getFilename(), '.entry')) {
                $count++;
            }
        }

        return $count;
    }

    public function pop(string &$identifier): ?EntryInterface
    {
        if (!is_dir($this->directory)) {
            throw new RuntimeException('Queue directory is unavailable', 1791890002);
        }

        $this->filesystemIterator ??= new FilesystemIterator(
            $this->directory,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME
        );
        while ($this->filesystemIterator->valid()) {
            $file = $this->filesystemIterator->current();
            $this->filesystemIterator->next();
            assert(is_string($file));
            if (is_link($file) || !is_file($file) || !str_ends_with($file, '.entry')) {
                continue;
            }

            $identifier = basename($file);
            $content = @file_get_contents($file);
            if ($content === false) {
                if (!file_exists($file)) {
                    continue;
                }

                throw new RuntimeException('Queue entry could not be read', 1791890003);
            }

            return $this->decodeEntry($content);
        }

        // A subsequent flush in a long-running worker must see newly added entries.
        $this->filesystemIterator = null;
        return null;
    }

    private function decodeEntry(string $content): EntryInterface
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            // Legacy compressed entries are raw DEFLATE, without a gzip header.
            $inflated = function_exists('gzinflate') ? @gzinflate($content) : false;
            if ($inflated === false) {
                throw $jsonException;
            }

            $data = json_decode($inflated, true, 512, JSON_THROW_ON_ERROR);
        }

        if (!is_array($data) || !isset($data['payload']) || !is_string($data['payload'])) {
            throw new InvalidArgumentException('Malformed queue entry payload', 1791890004);
        }

        $payload = $data['payload'];
        if (isset($data['payload_encoding'])) {
            if ($data['payload_encoding'] !== 'base64') {
                throw new InvalidArgumentException('Unknown queue payload encoding', 1791890005);
            }

            $payload = base64_decode($payload, true);
            if ($payload === false) {
                throw new InvalidArgumentException('Malformed base64 queue payload', 1791890006);
            }
        }

        return $this->entryFactory->createEntry($payload);
    }

    public function push(EntryInterface $entry): ?string
    {
        $data = $entry->jsonSerialize();
        if ($data instanceof stdClass) {
            $data = get_object_vars($data);
        }

        if (!is_array($data) || !isset($data['payload']) || !is_string($data['payload'])) {
            throw new InvalidArgumentException('Malformed serialized queue entry', 1791890007);
        }

        // Preserve the existing format for text envelopes; binary attachments need encoding.
        unset($data['payload_encoding']);
        if (preg_match('//u', $data['payload']) !== 1) {
            $data['payload'] = base64_encode($data['payload']);
            $data['payload_encoding'] = 'base64';
        }

        $content = json_encode($data, JSON_THROW_ON_ERROR);
        if ($this->compress && function_exists('gzdeflate')) {
            $compressed = gzdeflate($content);
            if ($compressed === false) {
                return null;
            }

            $content = $compressed;
        }

        $lock = @fopen($this->directory . '.queue.lock', 'c');
        if ($lock === false) {
            return null;
        }

        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                return null;
            }

            if ($this->limit > 0 && $this->count() > $this->limit - 1) {
                return null;
            }

            $identifier = bin2hex(random_bytes(16)) . '.entry';
            $temporary = $this->directory . $identifier . '.tmp';
            $stream = @fopen($temporary, 'xb');
            if ($stream === false) {
                return null;
            }

            try {
                if (@fwrite($stream, $content) !== strlen($content) || !@fflush($stream)) {
                    return null;
                }
            } finally {
                fclose($stream);
            }

            if (!@rename($temporary, $this->directory . $identifier)) {
                return null;
            }

            return $identifier;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }

            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function remove(string $identifier): bool
    {
        if ($identifier !== basename($identifier) || !str_ends_with($identifier, '.entry')) {
            return false;
        }

        $file = $this->directory . $identifier;
        return !is_link($file) && is_file($file) && @unlink($file);
    }
}
