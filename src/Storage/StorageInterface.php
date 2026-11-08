<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

/**
 * Where an app keeps its files. The same calls work on local disk (development), in memory
 * (tests) and on R2 (production), so switching is a setting, not a code change.
 */
interface StorageInterface
{
    /** Copy a file into the storage under $key (replacing what was there). */
    public function store(string $sourcePath, string $key, ?StoreOptions $options = null): StoredFile;

    /**
     * Write a string, or the whole of an open stream (rewound first when it can be; left open),
     * under $key. The content type is detected from the first bytes unless $options gives it.
     *
     * @param string|resource $contents
     */
    public function put(string $key, mixed $contents, ?StoreOptions $options = null): StoredFile;

    /** @throws FileNotFoundException */
    public function read(string $key): string;

    /**
     * The file as a stream at its start, for the caller to close.
     *
     * @return resource
     *
     * @throws FileNotFoundException
     */
    public function readStream(string $key);

    /** Size in bytes, null when there's no such file. */
    public function size(string $key): ?int;

    /** Delete; deleting a missing file is not an error. */
    public function delete(string $key): void;

    /**
     * Delete every file whose key starts with $prefix ("videos/42/" for a folder). Returns how
     * many were deleted. The prefix follows the key rules (an empty one is refused).
     */
    public function deletePrefix(string $prefix): int;

    public function exists(string $key): bool;

    /**
     * A link to the file: the permanent public link when the storage is public, otherwise a
     * temporary signed link (R2) valid for $expiresIn seconds.
     */
    public function url(string $key, int $expiresIn = 300): string;

    /**
     * A link that makes the browser save the file as $filename (any name, see ContentDisposition).
     * R2: always a temporary signed link, even on a public storage. Local and memory storages
     * can't set the name: it's url().
     */
    public function downloadUrl(string $key, string $filename, int $expiresIn = 300): string;

    /** "local", "memory" or "r2" */
    public function driver(): string;
}
