<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

/**
 * A storage browsers can upload to directly, in parts, through presigned links: the file never
 * passes through the app server. Only R2Storage has it (a local or memory storage has no address
 * a browser can PUT to); in Symfony, inject DirectUploads $privateStorage for a storage named
 * "private", which fails with a clear message when that storage's driver isn't r2.
 *
 * The flow: startUpload() → one uploadPartUrl() per part, the browser PUTs each part →
 * completeUpload() (part ETags are read from storage, not taken from the browser) → check
 * size() and firstBytes() → keep the file, or delete() it. abortUpload() drops an unfinished one.
 */
interface DirectUploads extends StorageInterface
{
    /** S3 limits: every part but the last is 5 MiB or more, and there are at most 10,000 parts. */
    public const MIN_PART_SIZE = 5 * 1024 * 1024;
    public const MAX_PARTS = 10000;

    /**
     * Returns the upload id. The content type comes from $options (default: application/octet-stream:
     * nothing has been uploaded yet to detect it from).
     */
    public function startUpload(string $key, ?StoreOptions $options = null): string;

    /** A link the browser PUTs part $partNumber (1 to 10,000) to. */
    public function uploadPartUrl(string $key, string $uploadId, int $partNumber, int $expiresIn = 3600): string;

    /** Assemble the uploaded parts into the file. */
    public function completeUpload(string $key, string $uploadId): void;

    /** Drop an unfinished upload and its parts; an unknown one is not an error. */
    public function abortUpload(string $key, string $uploadId): void;

    /**
     * The file's first bytes (fewer for a smaller file), e.g. to check what was really uploaded.
     *
     * @throws FileNotFoundException
     */
    public function firstBytes(string $key, int $bytes = 4096): string;
}
