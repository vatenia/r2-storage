<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

use Vatenia\R2Storage\R2Client;

/**
 * Builds a storage from settings at runtime, so the driver can come from an environment
 * variable (local in development, r2 in production, memory in tests) without changing code or
 * config files.
 */
final class StorageFactory
{
    public static function create(
        string $driver,
        R2Client $client,
        string $bucket = '',
        ?string $publicUrl = null,
        string $directory = '',
        string $localUrl = '/uploads',
    ): StorageInterface {
        return match (strtolower(trim($driver))) {
            'local' => new LocalStorage($directory, $localUrl),
            'memory' => new MemoryStorage($localUrl),
            'r2' => '' !== trim($bucket) ? new R2Storage($client, trim($bucket), $publicUrl) : throw new \InvalidArgumentException('The r2 storage driver needs a bucket.'),
            default => throw new \InvalidArgumentException(sprintf('Unknown storage driver "%s" (use "local", "memory" or "r2").', $driver)),
        };
    }

    /**
     * The storage itself when browsers can upload to it directly (driver r2), otherwise a clear
     * error: a local or memory storage has no address a browser could send parts to.
     */
    public static function directUploads(StorageInterface $storage, string $name = ''): DirectUploads
    {
        if ($storage instanceof DirectUploads) {
            return $storage;
        }

        throw new \LogicException(sprintf('The storage%s uses the "%s" driver, which has no direct (browser) uploads: use the r2 driver (in development, with MinIO or VersityGW and r2.presign_endpoint).', '' !== $name ? ' "'.$name.'"' : '', $storage->driver()));
    }
}
