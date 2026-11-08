<?php

declare(strict_types=1);

namespace Vatenia\R2Storage;

/**
 * Content-Disposition values for a file name, any name: "attachment; filename="..."" for plain
 * ASCII names, plus RFC 6266's filename*=UTF-8''... for others (the plain one is then an ASCII
 * fallback with "_" for what it can't hold). For presignedGetUrl() and StoreOptions.
 */
final class ContentDisposition
{
    /** The browser saves the file as $filename. */
    public static function attachment(string $filename): string
    {
        return self::build('attachment', $filename);
    }

    /** The browser shows the file when it can; $filename is used if it's saved. */
    public static function inline(string $filename): string
    {
        return self::build('inline', $filename);
    }

    private static function build(string $type, string $filename): string
    {
        $filename = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $filename));
        if ('' === $filename) {
            return $type;
        }
        // One "_" per character (per byte if the name isn't valid UTF-8).
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/u', '_', $filename) ?? (string) preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);
        if ($ascii === $filename) {
            return sprintf('%s; filename="%s"', $type, $filename);
        }

        return sprintf("%s; filename=\"%s\"; filename*=UTF-8''%s", $type, $ascii, rawurlencode($filename));
    }
}
