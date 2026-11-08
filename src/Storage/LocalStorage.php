<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

use Vatenia\R2Storage\Stream;

/** Files on local disk, e.g. public/uploads in development. */
final class LocalStorage implements StorageInterface
{
    private readonly string $directory;
    private readonly string $publicUrl;

    /**
     * @param string $directory where files are written (created if needed)
     * @param string $publicUrl how the web server serves that directory, e.g. "/uploads"
     */
    public function __construct(string $directory, string $publicUrl = '/uploads')
    {
        if ('' === trim($directory)) {
            throw new \InvalidArgumentException('A local storage needs a directory.');
        }
        $this->directory = rtrim($directory, '/');
        $this->publicUrl = rtrim($publicUrl, '/');
    }

    #[\Override]
    public function store(string $sourcePath, string $key, ?StoreOptions $options = null): StoredFile
    {
        Key::assertValid($key);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('"%s" is not a readable file.', $sourcePath));
        }
        $target = $this->target($key);
        if (!copy($sourcePath, $target)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $target));
        }

        return new StoredFile('local', $key, (int) filesize($target), $options->contentType ?? ContentType::detect($target), null, $this->url($key));
    }

    #[\Override]
    public function put(string $key, mixed $contents, ?StoreOptions $options = null): StoredFile
    {
        Key::assertValid($key);
        if (!\is_string($contents)) {
            Stream::rewind($contents);
        }
        $target = $this->target($key);
        if (false === file_put_contents($target, $contents)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $target));
        }
        clearstatcache(true, $target);

        return new StoredFile('local', $key, (int) filesize($target), $options->contentType ?? ContentType::detect($target), null, $this->url($key));
    }

    #[\Override]
    public function read(string $key): string
    {
        $contents = is_file($this->path($key)) ? file_get_contents($this->path($key)) : false;
        if (false === $contents) {
            throw new FileNotFoundException($key);
        }

        return $contents;
    }

    #[\Override]
    public function readStream(string $key)
    {
        $stream = is_file($this->path($key)) ? fopen($this->path($key), 'r') : false;
        if (false === $stream) {
            throw new FileNotFoundException($key);
        }

        return $stream;
    }

    #[\Override]
    public function size(string $key): ?int
    {
        $path = $this->path($key);
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : null;
    }

    #[\Override]
    public function delete(string $key): void
    {
        Key::assertValid($key);
        $path = $this->path($key);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException(sprintf('Could not delete "%s".', $path));
        }
    }

    #[\Override]
    public function deletePrefix(string $prefix): int
    {
        Key::assertValid(rtrim($prefix, '/'));
        // The deepest directory that can hold matching files: "a/b/" → a/b, "a/b" → a.
        $folder = str_contains($prefix, '/') ? substr($prefix, 0, (int) strrpos($prefix, '/')) : '';
        $root = '' !== $folder ? $this->directory.'/'.$folder : $this->directory;
        if (!is_dir($root)) {
            return 0;
        }
        $deleted = 0;
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $key = substr($entry->getPathname(), \strlen($this->directory) + 1);
            if ($entry->isDir()) {
                // Folders emptied by this call go too (rmdir fails on a non-empty one).
                if (str_starts_with($key.'/', $prefix)) {
                    @rmdir($entry->getPathname());
                }
            } elseif (str_starts_with($key, $prefix)) {
                if (!unlink($entry->getPathname())) {
                    throw new \RuntimeException(sprintf('Could not delete "%s".', $entry->getPathname()));
                }
                ++$deleted;
            }
        }
        if (str_ends_with($prefix, '/')) {
            @rmdir($root);
        }

        return $deleted;
    }

    #[\Override]
    public function exists(string $key): bool
    {
        Key::assertValid($key);

        return is_file($this->path($key));
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
        return 'local';
    }

    /** The file's path on disk. */
    public function path(string $key): string
    {
        Key::assertValid($key);

        return $this->directory.'/'.$key;
    }

    /** The file's path, its directory created. */
    private function target(string $key): string
    {
        $target = $this->path($key);
        $dir = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create "%s".', $dir));
        }

        return $target;
    }
}
