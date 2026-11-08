<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

/** Reading a key that isn't in the storage. */
final class FileNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $key, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('There is no file "%s" in the storage.', $key), 0, $previous);
    }
}
