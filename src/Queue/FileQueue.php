<?php

declare(strict_types=1);

namespace AUS\SentryAsync\Queue;

use Exception;
use FilesystemIterator;
use RuntimeException;

class FileQueue implements QueueInterface
{
    private string $directory;
    private int $limit;
    private bool $compress;
    public function __construct(int $limit, bool $compress, string $directory)
    {
        $this->directory = $directory;
        $this->limit = $limit;
        $this->compress = $compress;
        if (!file_exists($this->directory)) {
            try {
                if (!mkdir($concurrentDirectory = $this->directory, 0777, true) && !is_dir($concurrentDirectory)) {
                    throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                }
            } catch (Exception $exception) {}
        }
    }

    /**
     * @return \AUS\SentryAsync\Queue\Entry|null
     * @throws \JsonException
     */
    public function pop(): ?Entry
    {
        $file = null;
        if ($h = opendir($this->directory)) {
            while (($file = readdir($h)) !== false) {
                if ($file !== '.' && $file !== '..') {
                    break;
                }
            }
            closedir($h);
        }
        if ($file) {
            $absFile = $this->directory . $file;
            // $content = file_get_contents($absFile);
            $fp = fopen($absFile, 'rb');
            $mime = mime_content_type($absFile);
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

            unlink($absFile);
            if (!$content) {
                return null;
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!$data) {
                return null;
            }
            if (!isset($data['dsn'], $data['type'], $data['payload'])) {
                return null;
            }
            return new Entry($data['dsn'], $data['type'], $data['payload']);
        }
        return null;
    }

    public function push(Entry $entry): void
    {
        /** @noinspection JsonEncodingApiUsageInspection */
        $data = @json_encode($entry);
        if (!$data) {
            return;
        }

        if ($this->limit) {
            $fileCount = iterator_count(new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS));
            if ($fileCount > $this->limit) {
                return;
            }
        }

        $fileName = $this->directory . microtime(true) . md5($data) . '.entry';
        $fp = fopen($fileName, 'wb');
        if (!$fp) {
            return;
        }
        if ($this->compress) {
            @stream_filter_append($fp, 'zlib.deflate', STREAM_FILTER_WRITE);
        }
        @fwrite($fp, $data);
        @fclose($fp);
    }
}