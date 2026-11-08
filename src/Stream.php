<?php

declare(strict_types=1);

namespace Vatenia\R2Storage;

/**
 * Stream helpers shared by the client and the storages: an upload sends the whole stream, so a
 * seekable one is rewound first, and one that can't be rewound (php://input, a socket...) is
 * copied to php://temp (memory up to 2 MiB, then a temporary file) to know its size and hash.
 *
 * @internal
 */
final class Stream
{
    /**
     * The stream itself, rewound, or a rewound temporary copy when it can't be rewound
     * (the caller then closes the copy).
     *
     * @return resource
     */
    public static function seekable(mixed $stream)
    {
        if (self::rewind($stream)) {
            \assert(\is_resource($stream));

            return $stream;
        }
        \assert(\is_resource($stream));
        $copy = self::temp();
        if (false === stream_copy_to_stream($stream, $copy)) {
            fclose($copy);
            throw new \RuntimeException('The stream could not be read.');
        }
        rewind($copy);

        return $copy;
    }

    /**
     * Rewind when possible. False when the stream can't be rewound (it is then read from where it is).
     *
     * @throws \InvalidArgumentException when $stream isn't an open stream
     */
    public static function rewind(mixed $stream): bool
    {
        if (!\is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            throw new \InvalidArgumentException('Expected a string or an open stream resource.');
        }

        return stream_get_meta_data($stream)['seekable'] && rewind($stream);
    }

    /**
     * Size of a seekable stream; it is left at its start.
     *
     * @param resource $stream
     */
    public static function size($stream): int
    {
        fseek($stream, 0, \SEEK_END);
        $size = (int) ftell($stream);
        rewind($stream);

        return $size;
    }

    /**
     * SHA-256 of a seekable stream's contents; it is left at its start.
     *
     * @param resource $stream
     */
    public static function hash($stream): string
    {
        rewind($stream);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        rewind($stream);

        return hash_final($context);
    }

    /**
     * Up to $length bytes from the current position (fread() may return less on non-file streams).
     *
     * @param resource $stream
     */
    public static function read($stream, int $length): string
    {
        $data = '';
        while ($length > \strlen($data) && !feof($stream)) {
            $chunk = fread($stream, max(1, $length - \strlen($data)));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    /** @return resource */
    public static function temp()
    {
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new \RuntimeException('Could not open a temporary stream.');
        }

        return $stream;
    }
}
