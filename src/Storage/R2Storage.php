<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

use Vatenia\R2Storage\ContentDisposition;
use Vatenia\R2Storage\R2Client;
use Vatenia\R2Storage\R2Exception;
use Vatenia\R2Storage\Stream;

/**
 * Files in an R2 bucket. Public when $publicUrl is set (the bucket's custom domain or r2.dev
 * address: links are permanent), private otherwise (links are presigned and expire).
 * Browsers can also upload to it directly, in parts (DirectUploads).
 */
final class R2Storage implements DirectUploads
{
    private readonly ?string $publicUrl;

    public function __construct(
        private readonly R2Client $client,
        private readonly string $bucket,
        ?string $publicUrl = null,
    ) {
        $this->publicUrl = null !== $publicUrl && '' !== trim($publicUrl) ? rtrim(trim($publicUrl), '/') : null;
    }

    #[\Override]
    public function store(string $sourcePath, string $key, ?StoreOptions $options = null): StoredFile
    {
        Key::assertValid($key);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('"%s" is not a readable file.', $sourcePath));
        }
        $contentType = $options->contentType ?? ContentType::detect($sourcePath);
        $this->client->putFile($this->bucket, $key, $sourcePath, $contentType, self::headers($options));

        return $this->stored($key, (int) filesize($sourcePath), $contentType);
    }

    #[\Override]
    public function put(string $key, mixed $contents, ?StoreOptions $options = null): StoredFile
    {
        Key::assertValid($key);
        if (\is_string($contents)) {
            $contentType = $options->contentType ?? ContentType::detectBuffer(substr($contents, 0, 4096));
            $this->client->putContents($this->bucket, $key, $contents, $contentType, self::headers($options));

            return $this->stored($key, \strlen($contents), $contentType);
        }
        $handle = Stream::seekable($contents);
        try {
            $contentType = $options->contentType ?? ContentType::detectBuffer(Stream::read($handle, 4096));
            $size = Stream::size($handle);
            $this->client->putStream($this->bucket, $key, $handle, $contentType, self::headers($options));
        } finally {
            if ($handle !== $contents) {
                fclose($handle);
            }
        }

        return $this->stored($key, $size, $contentType);
    }

    #[\Override]
    public function read(string $key): string
    {
        Key::assertValid($key);
        try {
            return $this->client->get($this->bucket, $key);
        } catch (R2Exception $e) {
            throw 404 === $e->status ? new FileNotFoundException($key, $e) : $e;
        }
    }

    #[\Override]
    public function readStream(string $key)
    {
        Key::assertValid($key);
        try {
            return $this->client->getStream($this->bucket, $key);
        } catch (R2Exception $e) {
            throw 404 === $e->status ? new FileNotFoundException($key, $e) : $e;
        }
    }

    #[\Override]
    public function size(string $key): ?int
    {
        Key::assertValid($key);

        return $this->client->size($this->bucket, $key);
    }

    #[\Override]
    public function delete(string $key): void
    {
        Key::assertValid($key);
        $this->client->delete($this->bucket, $key);
    }

    #[\Override]
    public function deletePrefix(string $prefix): int
    {
        Key::assertValid(rtrim($prefix, '/'));

        return $this->client->deletePrefix($this->bucket, $prefix);
    }

    #[\Override]
    public function exists(string $key): bool
    {
        Key::assertValid($key);

        return $this->client->exists($this->bucket, $key);
    }

    #[\Override]
    public function url(string $key, int $expiresIn = 300): string
    {
        Key::assertValid($key);

        return null !== $this->publicUrl ? $this->publicUrl.'/'.Key::encode($key) : $this->client->presignedGetUrl($this->bucket, $key, $expiresIn);
    }

    #[\Override]
    public function downloadUrl(string $key, string $filename, int $expiresIn = 300): string
    {
        Key::assertValid($key);

        return $this->client->presignedGetUrl($this->bucket, $key, $expiresIn, ContentDisposition::attachment($filename));
    }

    #[\Override]
    public function startUpload(string $key, ?StoreOptions $options = null): string
    {
        Key::assertValid($key);

        return $this->client->createMultipartUpload($this->bucket, $key, $options->contentType ?? 'application/octet-stream', self::headers($options));
    }

    #[\Override]
    public function uploadPartUrl(string $key, string $uploadId, int $partNumber, int $expiresIn = 3600): string
    {
        Key::assertValid($key);

        return $this->client->presignedUploadPartUrl($this->bucket, $key, $uploadId, $partNumber, $expiresIn);
    }

    #[\Override]
    public function completeUpload(string $key, string $uploadId): void
    {
        Key::assertValid($key);
        $this->client->completeMultipartUpload($this->bucket, $key, $uploadId);
    }

    #[\Override]
    public function abortUpload(string $key, string $uploadId): void
    {
        Key::assertValid($key);
        $this->client->abortMultipartUpload($this->bucket, $key, $uploadId);
    }

    #[\Override]
    public function firstBytes(string $key, int $bytes = 4096): string
    {
        Key::assertValid($key);
        if ($bytes < 1) {
            throw new \InvalidArgumentException('Read at least one byte.');
        }
        try {
            return $this->client->get($this->bucket, $key, sprintf('bytes=0-%d', $bytes - 1));
        } catch (R2Exception $e) {
            throw 404 === $e->status ? new FileNotFoundException($key, $e) : $e;
        }
    }

    #[\Override]
    public function driver(): string
    {
        return 'r2';
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function client(): R2Client
    {
        return $this->client;
    }

    private function stored(string $key, int $size, string $contentType): StoredFile
    {
        return new StoredFile('r2', $key, $size, $contentType, $this->bucket, null !== $this->publicUrl ? $this->publicUrl.'/'.Key::encode($key) : null);
    }

    /** @return array<string, string> */
    private static function headers(?StoreOptions $options): array
    {
        return array_filter([
            'cache-control' => $options?->cacheControl,
            'content-disposition' => $options?->contentDisposition,
        ], static fn (?string $v): bool => null !== $v && '' !== $v);
    }
}
