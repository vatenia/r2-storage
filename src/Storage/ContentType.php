<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

/** @internal */
final class ContentType
{
    public static function detect(string $path): string
    {
        $type = (new \finfo(\FILEINFO_MIME_TYPE))->file($path);

        return \is_string($type) && '' !== $type ? $type : 'application/octet-stream';
    }

    /** From the first bytes of a file (4 KiB are plenty). */
    public static function detectBuffer(string $contents): string
    {
        $type = '' !== $contents ? (new \finfo(\FILEINFO_MIME_TYPE))->buffer($contents) : false;

        return \is_string($type) && '' !== $type ? $type : 'application/octet-stream';
    }
}
