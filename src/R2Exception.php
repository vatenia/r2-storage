<?php

declare(strict_types=1);

namespace Vatenia\R2Storage;

/** Any failure talking to R2 (or a missing setting), with R2's own error code when it sent one. */
final class R2Exception extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?string $errorCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
