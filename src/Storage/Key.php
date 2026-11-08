<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Storage;

/**
 * Object keys ("folder/name.jpg"): letters, digits, ".", "_", "-" in each segment, "/" between
 * segments. No "..", no leading or trailing "/", no empty segment, no segment starting with "."
 * (no hidden files such as .htaccess): a key can never point outside a local storage directory,
 * and it looks the same on disk and in R2.
 */
final class Key
{
    private const SEGMENT = '[A-Za-z0-9_-][A-Za-z0-9._-]*';

    public static function assertValid(string $key): void
    {
        if (\strlen($key) > 1024 || !preg_match('#^'.self::SEGMENT.'(/'.self::SEGMENT.')*$#', $key)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid storage key (letters, digits, ".", "_", "-", segments separated by "/").', $key));
        }
        foreach (explode('/', $key) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a valid storage key (no "." or ".." segment).', $key));
            }
        }
    }

    /** Each segment URL-encoded, for building links. */
    public static function encode(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
