<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

/** Where a file went: what an app saves to find, link or delete it later. */
final class StoredFile
{
    public function __construct(
        /** "local" or "r2" */
        public readonly string $driver,
        public readonly string $key,
        public readonly int $size,
        public readonly string $contentType,
        /** R2 bucket; null for local files */
        public readonly ?string $bucket = null,
        /** permanent public link, null when the storage is private (use StorageInterface::url()) */
        public readonly ?string $publicUrl = null,
    ) {
    }
}
