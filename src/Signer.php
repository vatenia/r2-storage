<?php

declare(strict_types=1);

namespace Vatenia\R2Storage;

/**
 * AWS Signature Version 4, as used by R2's S3 API: signed request headers and presigned URLs.
 * Pure (no I/O, the time is passed in) so it can be checked against AWS's published test values.
 *
 * Canonical URIs are passed already encoded (each path segment rawurlencoded, see R2Client::path()),
 * since S3 signs the path exactly as sent and doesn't encode it twice.
 */
final class Signer
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';
    public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    public function __construct(
        private readonly string $accessKeyId,
        #[\SensitiveParameter] private readonly string $secretAccessKey,
        private readonly string $region = 'auto',
        private readonly string $service = 's3',
    ) {
    }

    /**
     * Headers for a signed request: the given ones (names lowercased) plus x-amz-date and authorization.
     *
     * @param array<string, string> $query   decoded query parameters
     * @param array<string, string> $headers must include "host"; every header given is signed
     *
     * @return array<string, string>
     */
    public function signRequest(string $method, string $canonicalUri, array $query, array $headers, string $payloadHash, \DateTimeImmutable $now): array
    {
        $amzDate = self::amzDate($now);
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower(trim($name))] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
        $normalized['x-amz-date'] ??= $amzDate;
        if (!isset($normalized['host'])) {
            throw new \InvalidArgumentException('A signed request needs a "host" header.');
        }
        ksort($normalized, \SORT_STRING);

        $canonicalHeaders = '';
        foreach ($normalized as $name => $value) {
            $canonicalHeaders .= $name.':'.$value."\n";
        }
        $signedHeaders = implode(';', array_keys($normalized));

        $canonicalRequest = implode("\n", [strtoupper($method), $canonicalUri, self::canonicalQuery($query), $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = $this->scope($normalized['x-amz-date']);
        $signature = $this->signature($normalized['x-amz-date'], $scope, $canonicalRequest);

        $normalized['authorization'] = sprintf('%s Credential=%s/%s, SignedHeaders=%s, Signature=%s', self::ALGORITHM, $this->accessKeyId, $scope, $signedHeaders, $signature);

        return $normalized;
    }

    /**
     * Query string of a presigned URL (only the host is signed; the payload isn't).
     *
     * @param array<string, string> $extraQuery e.g. response-content-disposition
     */
    public function presignQuery(string $method, string $host, string $canonicalUri, int $expiresIn, \DateTimeImmutable $now, array $extraQuery = []): string
    {
        if ($expiresIn < 1 || $expiresIn > 604800) {
            throw new \InvalidArgumentException('A presigned URL lasts between 1 second and 7 days.');
        }
        $amzDate = self::amzDate($now);
        $scope = $this->scope($amzDate);
        $query = $extraQuery + [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => $this->accessKeyId.'/'.$scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalRequest = implode("\n", [strtoupper($method), $canonicalUri, self::canonicalQuery($query), 'host:'.$host."\n", 'host', self::UNSIGNED_PAYLOAD]);
        $query['X-Amz-Signature'] = $this->signature($amzDate, $scope, $canonicalRequest);

        return self::canonicalQuery($query);
    }

    /** @param array<array-key, string> $query numeric names arrive as int keys */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[rawurlencode((string) $name)] = rawurlencode($value);
        }
        ksort($pairs, \SORT_STRING);
        $out = [];
        foreach ($pairs as $name => $value) {
            $out[] = $name.'='.$value;
        }

        return implode('&', $out);
    }

    public static function amzDate(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function scope(string $amzDate): string
    {
        return substr($amzDate, 0, 8).'/'.$this->region.'/'.$this->service.'/aws4_request';
    }

    private function signature(string $amzDate, string $scope, string $canonicalRequest): string
    {
        $stringToSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);
        $key = hash_hmac('sha256', substr($amzDate, 0, 8), 'AWS4'.$this->secretAccessKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        return hash_hmac('sha256', $stringToSign, $key);
    }
}
