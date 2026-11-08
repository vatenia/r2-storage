<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vatenia\R2Storage\Signer;

/**
 * Checked against AWS's published Signature V4 examples, so the hand-written signing is
 * provably the same as AWS's (and R2's).
 */
final class SignerTest extends TestCase
{
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** AWS SigV4 test suite, "get-vanilla". */
    public function testGetVanilla(): void
    {
        $signer = new Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service');
        $headers = $signer->signRequest('GET', '/', [], ['Host' => 'example.amazonaws.com'], self::EMPTY_SHA256, new \DateTimeImmutable('2015-08-30T12:36:00Z'));

        self::assertSame('20150830T123600Z', $headers['x-amz-date']);
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['authorization'],
        );
    }

    /** S3 documentation, "GET Object" with a Range header (signed headers). */
    public function testS3GetObjectWithHeaders(): void
    {
        $signer = new Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
        $headers = $signer->signRequest('GET', '/test.txt', [], [
            'host' => 'examplebucket.s3.amazonaws.com',
            'range' => 'bytes=0-9',
            'x-amz-content-sha256' => self::EMPTY_SHA256,
        ], self::EMPTY_SHA256, new \DateTimeImmutable('2013-05-24T00:00:00Z'));

        self::assertStringEndsWith(
            'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['authorization'],
        );
    }

    /** S3 documentation, presigned URL example. */
    public function testS3PresignedUrl(): void
    {
        $signer = new Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
        $query = $signer->presignQuery('GET', 'examplebucket.s3.amazonaws.com', '/test.txt', 86400, new \DateTimeImmutable('2013-05-24T00:00:00Z'));

        self::assertSame(
            'X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404&X-Amz-SignedHeaders=host',
            $query,
        );
    }

    public function testQueryIsSortedAndEncoded(): void
    {
        // "~" is unreserved: AWS signs it unencoded.
        self::assertSame('a%20b=1%2F2&uploads=&z=~', Signer::canonicalQuery(['z' => '~', 'uploads' => '', 'a b' => '1/2']));
    }

    public function testPresignedLinksLastAtMostSevenDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Signer('k', 's'))->presignQuery('GET', 'h', '/b/k', 604801, new \DateTimeImmutable());
    }
}
