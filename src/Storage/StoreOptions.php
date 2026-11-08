<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

final class StoreOptions
{
    public function __construct(
        /** null: detected from the file's content */
        public readonly ?string $contentType = null,
        /** e.g. "public, max-age=31536000, immutable" for files whose key never changes */
        public readonly ?string $cacheControl = null,
        /** e.g. 'attachment; filename="report.pdf"' */
        public readonly ?string $contentDisposition = null,
    ) {
    }
}
