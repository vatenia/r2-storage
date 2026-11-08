<?php

declare(strict_types=1);

namespace Vatenia\R2Storage;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * R2 through its S3 API, signed with AWS Signature V4 (no AWS SDK): upload (multipart for
 * large files), download (whole, a byte range or streamed), size, delete (one key or a prefix),
 * listing, browser multipart uploads, and presigned links to download or upload directly.
 * Buckets are addressed path-style: <endpoint>/<bucket>/<key>.
 */
final class R2Client
{
    /** Files above this size are sent in parts (S3 parts are 5 MiB minimum, except the last). */
    public const DEFAULT_MULTIPART_THRESHOLD = 64 * 1024 * 1024;
    public const DEFAULT_PART_SIZE = 16 * 1024 * 1024;
    /** S3 limits: parts are numbered 1 to 10,000; DeleteObjects takes up to 1,000 keys. */
    private const MAX_PARTS = 10000;
    private const DELETE_BATCH = 1000;

    private readonly Signer $signer;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly R2Config $config,
        ?\Closure $clock = null,
        private readonly int $retries = 2,
        private readonly int $multipartThreshold = self::DEFAULT_MULTIPART_THRESHOLD,
        private readonly int $partSize = self::DEFAULT_PART_SIZE,
    ) {
        $this->signer = new Signer($config->accessKeyId, $config->secretAccessKey, $config->region);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Upload a file. Extra headers: cache-control, content-disposition, x-amz-meta-*.
     *
     * @param array<string, string> $headers
     */
    public function putFile(string $bucket, string $key, string $sourcePath, string $contentType = 'application/octet-stream', array $headers = []): void
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new R2Exception(sprintf('"%s" is not a readable file.', $sourcePath));
        }
        $handle = fopen($sourcePath, 'r');
        if (false === $handle) {
            throw new R2Exception(sprintf('"%s" could not be opened.', $sourcePath));
        }
        try {
            $this->putStream($bucket, $key, $handle, $contentType, $headers);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Upload from an open stream, which is left open. The whole stream is sent: a seekable one is
     * rewound first; one that can't be (php://input...) is first copied to a temporary stream.
     *
     * @param resource              $stream
     * @param array<string, string> $headers
     */
    public function putStream(string $bucket, string $key, $stream, string $contentType = 'application/octet-stream', array $headers = []): void
    {
        $handle = Stream::seekable($stream);
        try {
            $size = Stream::size($handle);
            if ($size > $this->multipartThreshold) {
                $this->putMultipart($bucket, $key, $handle, $size, $contentType, $headers);

                return;
            }
            $this->send('PUT', $bucket, $key, [], ['content-type' => $contentType, 'content-length' => (string) $size] + self::lower($headers), Stream::hash($handle), static function () use ($handle) {
                rewind($handle);

                return $handle;
            });
        } finally {
            if ($handle !== $stream) {
                fclose($handle);
            }
        }
    }

    /**
     * Upload a string (small generated files, thumbnails...).
     *
     * @param array<string, string> $headers
     */
    public function putContents(string $bucket, string $key, string $contents, string $contentType = 'application/octet-stream', array $headers = []): void
    {
        $this->send('PUT', $bucket, $key, [], ['content-type' => $contentType, 'content-length' => (string) \strlen($contents)] + self::lower($headers), hash('sha256', $contents), static fn (): string => $contents);
    }

    /**
     * An object's contents, or part of it with $range ("bytes=0-4095": the first 4 KiB). A range
     * starting past the end gives an empty string.
     */
    public function get(string $bucket, string $key, ?string $range = null): string
    {
        $headers = [];
        if (null !== $range) {
            if (!preg_match('/^bytes=(\d+-\d*|-\d+)$/', $range)) {
                throw new R2Exception(sprintf('"%s" is not a byte range such as "bytes=0-4095".', $range));
            }
            $headers['range'] = $range;
        }
        $response = $this->send('GET', $bucket, $key, [], $headers, hash('sha256', ''), null, null !== $range ? [416] : []);

        return 416 === $response->getStatusCode() ? '' : $response->getContent();
    }

    /**
     * An object's contents as a stream, at its start. The body is copied to php://temp as it
     * arrives (memory up to 2 MiB, then a temporary file), so a large object doesn't fill memory.
     * The caller closes it.
     *
     * @return resource
     */
    public function getStream(string $bucket, string $key)
    {
        $response = $this->send('GET', $bucket, $key, [], [], hash('sha256', ''), null, [], false);
        $stream = Stream::temp();
        try {
            foreach ($this->http->stream($response) as $chunk) {
                fwrite($stream, $chunk->getContent());
            }
        } catch (TransportExceptionInterface $e) {
            fclose($stream);
            throw new R2Exception(sprintf('R2 GET %s failed: %s', self::path($bucket, $key), $e->getMessage()), 0, null, $e);
        }
        rewind($stream);

        return $stream;
    }

    /** An object's size in bytes (a HEAD request), null when there's no such object. */
    public function size(string $bucket, string $key): ?int
    {
        $response = $this->send('HEAD', $bucket, $key, [], [], hash('sha256', ''), null, [404]);
        if (404 === $response->getStatusCode()) {
            return null;
        }
        $length = $response->getHeaders()['content-length'][0] ?? '';
        if (!ctype_digit($length)) {
            throw new R2Exception(sprintf('R2 sent no size for %s.', self::path($bucket, $key)));
        }

        return (int) $length;
    }

    /** Delete an object; deleting one that isn't there is not an error. */
    public function delete(string $bucket, string $key): void
    {
        $this->send('DELETE', $bucket, $key, [], [], hash('sha256', ''), null, [404]);
    }

    public function exists(string $bucket, string $key): bool
    {
        return 200 === $this->send('HEAD', $bucket, $key, [], [], hash('sha256', ''), null, [404])->getStatusCode();
    }

    /**
     * Keys starting with $prefix ("" for all), in key order, read a page (1,000 keys) at a time.
     *
     * @return \Generator<int, string>
     */
    public function listKeys(string $bucket, string $prefix = ''): \Generator
    {
        $token = '';
        while (true) {
            $query = ['list-type' => '2'] + ('' !== $prefix ? ['prefix' => $prefix] : []) + ('' !== $token ? ['continuation-token' => $token] : []);
            $xml = self::xml($this->send('GET', $bucket, null, $query, [], hash('sha256', ''))->getContent());
            foreach (self::children($xml, 'Contents') as $object) {
                yield (string) $object->Key;
            }
            $next = 'true' === (string) $xml->IsTruncated ? (string) $xml->NextContinuationToken : '';
            if ('' === $next || $next === $token) {
                return;
            }
            $token = $next;
        }
    }

    /**
     * Delete every object whose key starts with $prefix (e.g. "videos/42/"), 1,000 per request.
     * Returns how many were deleted. An empty prefix is refused: it would empty the bucket.
     */
    public function deletePrefix(string $bucket, string $prefix): int
    {
        if ('' === $prefix) {
            throw new R2Exception('deletePrefix() needs a prefix: an empty one would empty the whole bucket.');
        }
        $deleted = 0;
        $batch = [];
        foreach ($this->listKeys($bucket, $prefix) as $key) {
            $batch[] = $key;
            if (self::DELETE_BATCH === \count($batch)) {
                $deleted += $this->deleteKeys($bucket, $batch);
                $batch = [];
            }
        }

        return $deleted + ([] !== $batch ? $this->deleteKeys($bucket, $batch) : 0);
    }

    /**
     * Start a multipart upload; its parts are then sent with presignedUploadPartUrl() (by a
     * browser) and assembled with completeMultipartUpload(). Returns the upload id.
     *
     * @param array<string, string> $headers cache-control, content-disposition, x-amz-meta-*
     */
    public function createMultipartUpload(string $bucket, string $key, string $contentType = 'application/octet-stream', array $headers = []): string
    {
        $response = $this->send('POST', $bucket, $key, ['uploads' => ''], ['content-type' => $contentType] + self::lower($headers), hash('sha256', ''));
        $uploadId = (string) (self::xml($response->getContent())->UploadId ?? '');
        if ('' === $uploadId) {
            throw new R2Exception('R2 did not return an upload id for the multipart upload.');
        }

        return $uploadId;
    }

    /**
     * A link a browser can PUT one part to (parts are numbered from 1 to 10,000; all but the last
     * are 5 MiB or more). The answer's ETag header needn't be sent back: see completeMultipartUpload().
     */
    public function presignedUploadPartUrl(string $bucket, string $key, string $uploadId, int $partNumber, int $expiresIn = 3600): string
    {
        self::assertPart($uploadId, $partNumber);

        return $this->presign('PUT', $bucket, $key, $expiresIn, ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId]);
    }

    /**
     * The parts uploaded so far, as stored: part number => ETag, in order.
     *
     * @return array<int, string>
     */
    public function listParts(string $bucket, string $key, string $uploadId): array
    {
        self::assertPart($uploadId, 1);
        $parts = [];
        $marker = '';
        while (true) {
            $query = ['uploadId' => $uploadId] + ('' !== $marker ? ['part-number-marker' => $marker] : []);
            $xml = self::xml($this->send('GET', $bucket, $key, $query, [], hash('sha256', ''))->getContent());
            foreach (self::children($xml, 'Part') as $part) {
                $parts[(int) $part->PartNumber] = (string) $part->ETag;
            }
            $next = 'true' === (string) $xml->IsTruncated ? (string) $xml->NextPartNumberMarker : '';
            if ('' === $next || $next === $marker) {
                break;
            }
            $marker = $next;
        }
        ksort($parts);

        return $parts;
    }

    /**
     * Assemble the parts into the object. Without $parts (part number => ETag) they are read
     * with listParts(): ETags come from storage, never from the browser that sent the parts.
     *
     * @param array<int, string>|null $parts
     */
    public function completeMultipartUpload(string $bucket, string $key, string $uploadId, ?array $parts = null): void
    {
        $parts ??= $this->listParts($bucket, $key, $uploadId);
        if ([] === $parts) {
            throw new R2Exception('The multipart upload has no parts to assemble.');
        }
        ksort($parts);
        $xml = '<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        foreach ($parts as $number => $etag) {
            $xml .= sprintf('<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>', $number, htmlspecialchars($etag, \ENT_XML1));
        }
        $xml .= '</CompleteMultipartUpload>';
        $done = $this->send('POST', $bucket, $key, ['uploadId' => $uploadId], ['content-type' => 'application/xml', 'content-length' => (string) \strlen($xml)], hash('sha256', $xml), static fn (): string => $xml);
        // S3 can answer 200 with an <Error> body when completing fails.
        $body = $done->getContent();
        if (str_contains($body, '<Error>')) {
            $error = self::xml($body);
            throw new R2Exception(sprintf('R2 multipart upload failed: %s', (string) ($error->Message ?? 'unknown error')), 200, (string) ($error->Code ?? '') ?: null);
        }
    }

    /** Drop an unfinished multipart upload and its parts; an unknown (or finished) one is not an error. */
    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        self::assertPart($uploadId, 1);
        $this->send('DELETE', $bucket, $key, ['uploadId' => $uploadId], [], hash('sha256', ''), null, [404]);
    }

    /**
     * A link anyone can open for $expiresIn seconds, e.g. a private file's download. For the
     * disposition, ContentDisposition::attachment($name) handles any file name.
     */
    public function presignedGetUrl(string $bucket, string $key, int $expiresIn = 300, ?string $contentDisposition = null): string
    {
        $extra = null !== $contentDisposition ? ['response-content-disposition' => $contentDisposition] : [];

        return $this->presign('GET', $bucket, $key, $expiresIn, $extra);
    }

    /** A link a browser or phone can PUT a file to for $expiresIn seconds (direct upload, no app server in between). */
    public function presignedPutUrl(string $bucket, string $key, int $expiresIn = 300): string
    {
        return $this->presign('PUT', $bucket, $key, $expiresIn);
    }

    /** The object's address (path-style), e.g. for logs. */
    public function objectUrl(string $bucket, string $key): string
    {
        return $this->config->endpoint.self::path($bucket, $key);
    }

    /** "/bucket/key" with each segment encoded, as sent and as signed. */
    public static function path(string $bucket, string $key): string
    {
        $segments = array_map('rawurlencode', explode('/', trim($key, '/')));

        return '/'.rawurlencode($bucket).('' !== $key ? '/'.implode('/', $segments) : '');
    }

    /**
     * Signed for the presign endpoint (the browser-facing address).
     *
     * @param array<string, string> $extraQuery
     */
    private function presign(string $method, string $bucket, string $key, int $expiresIn, array $extraQuery = []): string
    {
        $this->config->assertComplete();
        self::assertKey($bucket, $key);
        $path = self::path($bucket, $key);

        return $this->config->presignEndpoint.$path.'?'.$this->signer->presignQuery($method, $this->config->presignHost(), $path, $expiresIn, ($this->clock)(), $extraQuery);
    }

    /**
     * @param resource              $handle  seekable
     * @param array<string, string> $headers
     */
    private function putMultipart(string $bucket, string $key, $handle, int $size, string $contentType, array $headers): void
    {
        $uploadId = $this->createMultipartUpload($bucket, $key, $contentType, $headers);
        try {
            $etags = [];
            $partNumber = 0;
            for ($offset = 0; $offset < $size; $offset += $this->partSize) {
                ++$partNumber;
                fseek($handle, $offset);
                $chunk = Stream::read($handle, max(1, min($this->partSize, $size - $offset)));
                $response = $this->send('PUT', $bucket, $key, ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId], ['content-length' => (string) \strlen($chunk)], hash('sha256', $chunk), static fn (): string => $chunk);
                $etags[$partNumber] = $response->getHeaders()['etag'][0] ?? '';
            }
            $this->completeMultipartUpload($bucket, $key, $uploadId, $etags);
        } catch (\Throwable $e) {
            try {
                $this->abortMultipartUpload($bucket, $key, $uploadId);
            } catch (\Throwable) {
                // The upload expires on its own; report the first error.
            }
            throw $e instanceof R2Exception ? $e : new R2Exception('R2 multipart upload failed: '.$e->getMessage(), 0, null, $e);
        }
    }

    /**
     * DeleteObjects: up to 1,000 keys in one request (S3 requires its Content-MD5).
     *
     * @param list<string> $keys
     */
    private function deleteKeys(string $bucket, array $keys): int
    {
        $xml = '<Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Quiet>true</Quiet>';
        foreach ($keys as $key) {
            $xml .= '<Object><Key>'.htmlspecialchars($key, \ENT_XML1).'</Key></Object>';
        }
        $xml .= '</Delete>';
        $response = $this->send('POST', $bucket, null, ['delete' => ''], ['content-type' => 'application/xml', 'content-length' => (string) \strlen($xml), 'content-md5' => base64_encode(md5($xml, true))], hash('sha256', $xml), static fn (): string => $xml);
        // Quiet mode: only the keys that couldn't be deleted are listed.
        $errors = self::children(self::xml($response->getContent()), 'Error');
        if ([] !== $errors) {
            $first = $errors[0];
            throw new R2Exception(sprintf('R2 could not delete %d of %d objects (%s: %s: %s).', \count($errors), \count($keys), (string) ($first->Key ?? ''), (string) ($first->Code ?? ''), (string) ($first->Message ?? '')), 200, (string) ($first->Code ?? '') ?: null);
        }

        return \count($keys);
    }

    /**
     * Send a signed request; retry network errors, 5xx and 429 (with a short pause).
     *
     * @param array<string, string>                $query
     * @param array<string, string>                $headers
     * @param (\Closure(): (string|resource))|null $body    a fresh body for each attempt
     * @param list<int>                            $accept  other statuses that aren't errors
     * @param bool                                 $buffer  false when the caller streams the body
     */
    private function send(string $method, string $bucket, ?string $key, array $query, array $headers, string $payloadHash, ?\Closure $body = null, array $accept = [], bool $buffer = true): ResponseInterface
    {
        $this->config->assertComplete();
        self::assertKey($bucket, $key);
        $path = self::path($bucket, $key ?? ''); // no key: the bucket itself (listing, batch delete)
        $url = $this->config->endpoint.$path.([] !== $query ? '?'.Signer::canonicalQuery($query) : '');

        for ($attempt = 0;; ++$attempt) {
            $signed = $this->signer->signRequest($method, $path, $query, ['host' => $this->config->host(), 'x-amz-content-sha256' => $payloadHash] + $headers, $payloadHash, ($this->clock)());
            unset($signed['host']); // the HTTP client sets it (same value)
            $options = ['headers' => $signed, 'buffer' => $buffer];
            if (null !== $body) {
                $options['body'] = $body();
            }
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();
                if (($status >= 500 || 429 === $status) && $attempt < $this->retries) {
                    usleep(200_000 << $attempt);
                    continue;
                }
                if (($status >= 200 && $status < 300) || \in_array($status, $accept, true)) {
                    return $response;
                }

                throw self::error($method, $path, $status, 'HEAD' === $method ? '' : $response->getContent(false));
            } catch (TransportExceptionInterface $e) {
                if ($attempt < $this->retries) {
                    usleep(200_000 << $attempt);
                    continue;
                }
                throw new R2Exception(sprintf('R2 %s %s failed: %s', $method, $path, $e->getMessage()), 0, null, $e);
            }
        }
    }

    /** The exception for an error answer, with R2's error code and message when the body has them. */
    private static function error(string $method, string $path, int $status, string $content): R2Exception
    {
        $error = '' !== $content && str_starts_with(ltrim($content), '<') ? self::xml($content) : null;
        $code = null !== $error ? (string) ($error->Code ?? '') : '';
        $message = null !== $error ? (string) ($error->Message ?? '') : '';

        return new R2Exception(sprintf('R2 %s %s failed with status %d%s.', $method, $path, $status, '' !== $code ? " ({$code}: {$message})" : ''), $status, '' !== $code ? $code : null);
    }

    private static function assertKey(string $bucket, ?string $key): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new R2Exception(sprintf('"%s" is not a valid bucket name.', $bucket));
        }
        if (null !== $key && ('' === $key || \strlen($key) > 1024 || str_contains($key, "\0"))) {
            throw new R2Exception('An object key is 1 to 1024 bytes, without NUL characters.');
        }
    }

    private static function assertPart(string $uploadId, int $partNumber): void
    {
        if ('' === $uploadId) {
            throw new R2Exception('A multipart upload id is needed.');
        }
        if ($partNumber < 1 || $partNumber > self::MAX_PARTS) {
            throw new R2Exception(sprintf('Part numbers go from 1 to %d, not %d.', self::MAX_PARTS, $partNumber));
        }
    }

    private static function xml(string $content): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, \LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (false === $xml) {
            throw new R2Exception('R2 sent a response that is not valid XML.');
        }

        return $xml;
    }

    /**
     * The child elements named $name (repeated elements such as <Part> or <Contents>).
     *
     * @return list<\SimpleXMLElement>
     */
    private static function children(\SimpleXMLElement $xml, string $name): array
    {
        $found = [];
        foreach ($xml->children() ?? [] as $child) {
            if ($name === $child->getName()) {
                $found[] = $child;
            }
        }

        return $found;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function lower(array $headers): array
    {
        return array_change_key_case($headers, \CASE_LOWER);
    }
}
