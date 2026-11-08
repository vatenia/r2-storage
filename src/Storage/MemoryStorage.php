<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

use Vatenia\R2Storage\Stream;

/** Files in memory, gone at the end of the process: for tests (driver "memory"). */
final class MemoryStorage implements StorageInterface
{
    /** @var array<array-key, string> key => contents (numeric keys such as "2026" become ints) */
    private array $files = [];
    private readonly string $publicUrl;

    /** @param string $publicUrl what url() puts before keys */
    public function __construct(string $publicUrl = '/uploads')
    {
        $this->publicUrl = rtrim($publicUrl, '/');
    }

    #[\Override]
    public function store(string $sourcePath, string $key, ?StoreOptions $options = null): StoredFile
    {
        Key::assertValid($key);
        $contents = is_file($sourcePath) && is_readable($sourcePath) ? file_get_contents($sourcePath) : false;
        if (false === $contents) {
            throw new \RuntimeException(sprintf('"%s" is not a readable file.', $sourcePath));
        }

        return $this->put($key, $contents, $options ?? new StoreOptions(ContentType::detect($sourcePath)));
    }

    #[\Override]
    public function put(string $key, mixed $contents, ?StoreOptions $options = null): StoredFile
    {
        Key::assertValid($key);
        if (!\is_string($contents)) {
            Stream::rewind($contents);
            $contents = stream_get_contents($contents);
            if (false === $contents) {
                throw new \RuntimeException('The stream could not be read.');
            }
        }
        $this->files[$key] = $contents;

        return new StoredFile('memory', $key, \strlen($contents), $options->contentType ?? ContentType::detectBuffer(substr($contents, 0, 4096)), null, $this->url($key));
    }

    #[\Override]
    public function read(string $key): string
    {
        Key::assertValid($key);

        return $this->files[$key] ?? throw new FileNotFoundException($key);
    }

    #[\Override]
    public function readStream(string $key)
    {
        $stream = Stream::temp();
        fwrite($stream, $this->read($key));
        rewind($stream);

        return $stream;
    }

    #[\Override]
    public function size(string $key): ?int
    {
        Key::assertValid($key);

        return isset($this->files[$key]) ? \strlen($this->files[$key]) : null;
    }

    #[\Override]
    public function delete(string $key): void
    {
        Key::assertValid($key);
        unset($this->files[$key]);
    }

    #[\Override]
    public function deletePrefix(string $prefix): int
    {
        Key::assertValid(rtrim($prefix, '/'));
        $deleted = 0;
        foreach (array_keys($this->files) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($this->files[$key]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    #[\Override]
    public function exists(string $key): bool
    {
        Key::assertValid($key);

        return isset($this->files[$key]);
    }

    #[\Override]
    public function url(string $key, int $expiresIn = 300): string
    {
        Key::assertValid($key);

        return $this->publicUrl.'/'.Key::encode($key);
    }

    #[\Override]
    public function downloadUrl(string $key, string $filename, int $expiresIn = 300): string
    {
        return $this->url($key, $expiresIn);
    }

    #[\Override]
    public function driver(): string
    {
        return 'memory';
    }

    /**
     * Every key, sorted (for assertions in tests).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($this->files));
        sort($keys);

        return $keys;
    }
}
