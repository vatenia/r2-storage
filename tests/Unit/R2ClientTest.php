<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Vatenia\R2Storage\ContentDisposition;
use Vatenia\R2Storage\R2Client;
use Vatenia\R2Storage\R2Config;
use Vatenia\R2Storage\R2Exception;
use Vatenia\R2Storage\Signer;

final class R2ClientTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ('' !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @param list<MockResponse>                                                                     $responses
     * @param list<array{method: string, url: string, headers: array<string, string>, body: string}> $requests
     */
    private function client(array $responses, array &$requests, int $multipartThreshold = R2Client::DEFAULT_MULTIPART_THRESHOLD, int $partSize = R2Client::DEFAULT_PART_SIZE, ?R2Config $config = null): R2Client
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$requests): MockResponse {
            $headers = [];
            $lines = $options['headers'] ?? [];
            foreach (\is_array($lines) ? $lines : [] as $line) {
                if (\is_string($line) && str_contains($line, ': ')) {
                    [$name, $value] = explode(': ', $line, 2);
                    $headers[strtolower($name)] = $value;
                }
            }
            $body = $options['body'] ?? '';
            if (\is_resource($body)) {
                $body = (string) stream_get_contents($body);
            } elseif ($body instanceof \Closure) {
                $chunks = '';
                while (\is_string($chunk = $body(65536)) && '' !== $chunk) {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }
            $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => \is_string($body) ? $body : ''];
            $response = array_shift($responses);
            self::assertNotNull($response, "unexpected request {$method} {$url}");

            return $response;
        });

        return new R2Client($http, $config ?? new R2Config('https://acc123.r2.cloudflarestorage.com', 'AKID', 'SECRET'), static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-04T10:00:00Z'), 2, $multipartThreshold, $partSize);
    }

    private function tempFile(string $contents): string
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'r2t');
        file_put_contents($this->file, $contents);

        return $this->file;
    }

    public function testPutFileSendsASignedPathStyleRequest(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('', ['http_code' => 200])], $requests);
        $client->putFile('my-bucket', 'projects/photo 1.jpg', $this->tempFile('jpeg bytes'), 'image/jpeg', ['Cache-Control' => 'public, max-age=60']);

        self::assertCount(1, $requests);
        [$r] = $requests;
        self::assertSame('PUT', $r['method']);
        self::assertSame('https://acc123.r2.cloudflarestorage.com/my-bucket/projects/photo%201.jpg', $r['url']);
        self::assertSame(hash('sha256', 'jpeg bytes'), $r['headers']['x-amz-content-sha256']);
        self::assertSame('image/jpeg', $r['headers']['content-type']);
        self::assertSame('public, max-age=60', $r['headers']['cache-control']);
        self::assertSame('20261104T100000Z', $r['headers']['x-amz-date']);
        self::assertSame('jpeg bytes', $r['body']);
        self::assertMatchesRegularExpression('#^AWS4-HMAC-SHA256 Credential=AKID/20261104/auto/s3/aws4_request, SignedHeaders=cache-control;content-length;content-type;host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $r['headers']['authorization']);
    }

    public function testDeleteToleratesMissingObjectsAndExistsReadsTheStatus(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 200]), new MockResponse('', ['http_code' => 404])], $requests);
        $client->delete('my-bucket', 'gone.jpg');
        self::assertTrue($client->exists('my-bucket', 'here.jpg'));
        self::assertFalse($client->exists('my-bucket', 'gone.jpg'));
        self::assertSame(['DELETE', 'HEAD', 'HEAD'], array_column($requests, 'method'));
    }

    public function testRetriesServerErrorsAndNetworkFailures(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('', ['error' => 'connection reset']),
            new MockResponse('<Error><Code>InternalError</Code></Error>', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 200]),
        ], $requests);
        $client->putContents('my-bucket', 'a.txt', 'hello', 'text/plain');
        self::assertCount(3, $requests);
        self::assertSame(['hello', 'hello', 'hello'], array_column($requests, 'body'), 'the body is sent again on each attempt');
    }

    public function testErrorsCarryR2sCodeAndStatus(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', ['http_code' => 403])], $requests);
        try {
            $client->putContents('my-bucket', 'a.txt', 'x');
            self::fail('expected an R2Exception');
        } catch (R2Exception $e) {
            self::assertSame(403, $e->status);
            self::assertSame('AccessDenied', $e->errorCode);
            self::assertStringContainsString('AccessDenied: Access Denied', $e->getMessage());
        }
    }

    public function testLargeFilesAreUploadedInParts(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('<InitiateMultipartUploadResult><UploadId>up-1</UploadId></InitiateMultipartUploadResult>'),
            new MockResponse('', ['response_headers' => ['ETag' => '"e1"']]),
            new MockResponse('', ['response_headers' => ['ETag' => '"e2"']]),
            new MockResponse('', ['response_headers' => ['ETag' => '"e3"']]),
            new MockResponse('<CompleteMultipartUploadResult><Key>big.bin</Key></CompleteMultipartUploadResult>'),
        ], $requests, 10, 4);
        $client->putFile('my-bucket', 'big.bin', $this->tempFile('0123456789AB'), 'application/octet-stream');

        self::assertSame(['POST', 'PUT', 'PUT', 'PUT', 'POST'], array_column($requests, 'method'));
        self::assertStringEndsWith('/my-bucket/big.bin?uploads=', $requests[0]['url']);
        self::assertStringEndsWith('?partNumber=2&uploadId=up-1', $requests[2]['url']);
        self::assertSame(['0123', '4567', '89AB'], [$requests[1]['body'], $requests[2]['body'], $requests[3]['body']]);
        self::assertStringContainsString('<Part><PartNumber>3</PartNumber><ETag>"e3"</ETag></Part>', $requests[4]['body']);
    }

    public function testAFailedMultipartUploadIsAborted(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('<InitiateMultipartUploadResult><UploadId>up-2</UploadId></InitiateMultipartUploadResult>'),
            new MockResponse('<Error><Code>AccessDenied</Code><Message>no</Message></Error>', ['http_code' => 403]),
            new MockResponse('', ['http_code' => 204]),
        ], $requests, 10, 4);
        try {
            $client->putFile('my-bucket', 'big.bin', $this->tempFile('0123456789AB'));
            self::fail('expected an R2Exception');
        } catch (R2Exception $e) {
            self::assertSame('AccessDenied', $e->errorCode);
        }
        self::assertSame('DELETE', $requests[2]['method']);
        self::assertStringEndsWith('?uploadId=up-2', $requests[2]['url']);
    }

    public function testPresignedLinks(): void
    {
        $requests = [];
        $client = $this->client([], $requests);
        $get = $client->presignedGetUrl('my-bucket', 'docs/report.pdf', 600, 'attachment; filename="report.pdf"');
        self::assertStringStartsWith('https://acc123.r2.cloudflarestorage.com/my-bucket/docs/report.pdf?', $get);
        self::assertStringContainsString('X-Amz-Expires=600', $get);
        self::assertStringContainsString('response-content-disposition=attachment%3B%20filename%3D%22report.pdf%22', $get);
        self::assertMatchesRegularExpression('/X-Amz-Signature=[0-9a-f]{64}/', $get);
        self::assertStringContainsString('X-Amz-SignedHeaders=host', $client->presignedPutUrl('my-bucket', 'in/upload.jpg'));
        self::assertCount(0, $requests, 'presigning makes no request');
    }

    public function testMissingSettingsAreReportedClearly(): void
    {
        $client = new R2Client(new MockHttpClient(), new R2Config('', '', ''));
        $this->expectException(R2Exception::class);
        $this->expectExceptionMessage('R2 is not configured: missing endpoint, access key id, secret access key.');
        $client->exists('my-bucket', 'a.jpg');
    }

    public function testRejectsInvalidBucketNames(): void
    {
        $requests = [];
        $this->expectException(R2Exception::class);
        $this->client([], $requests)->delete('Not_A_Bucket', 'a.jpg');
    }

    public function testNetworkFailureAfterRetriesIsAnR2Exception(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('', ['error' => 'down']), new MockResponse('', ['error' => 'down']), new MockResponse('', ['error' => 'down'])], $requests);
        try {
            $client->delete('my-bucket', 'a.jpg');
            self::fail('expected an R2Exception');
        } catch (R2Exception $e) {
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
        self::assertCount(3, $requests);
    }

    public function testPutStreamSendsTheWholeStreamAndLeavesItOpen(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse(''), new MockResponse('')], $requests);
        $stream = fopen('php://temp', 'w+');
        \assert(false !== $stream);
        fwrite($stream, 'stream bytes');
        $client->putStream('my-bucket', 'a.bin', $stream, 'application/pdf'); // not rewound by the caller
        self::assertSame('stream bytes', $requests[0]['body']);
        self::assertSame('12', $requests[0]['headers']['content-length']);
        self::assertSame(hash('sha256', 'stream bytes'), $requests[0]['headers']['x-amz-content-sha256']);
        self::assertSame('stream', get_resource_type($stream), 'still open: the caller closes it');
        fclose($stream);

        // a stream that can't be rewound is copied first
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \assert(false !== $pair);
        fwrite($pair[0], 'from a socket');
        fclose($pair[0]);
        $client->putStream('my-bucket', 'b.bin', $pair[1]);
        fclose($pair[1]);
        self::assertSame('from a socket', $requests[1]['body']);
        self::assertSame(hash('sha256', 'from a socket'), $requests[1]['headers']['x-amz-content-sha256']);
    }

    public function testALargeStreamIsUploadedInParts(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('<InitiateMultipartUploadResult><UploadId>up-3</UploadId></InitiateMultipartUploadResult>'),
            new MockResponse('', ['response_headers' => ['ETag' => '"e1"']]),
            new MockResponse('', ['response_headers' => ['ETag' => '"e2"']]),
            new MockResponse('<CompleteMultipartUploadResult/>'),
        ], $requests, 5, 4);
        $stream = fopen('php://temp', 'w+');
        \assert(false !== $stream);
        fwrite($stream, '0123456');
        $client->putStream('my-bucket', 'big.bin', $stream, 'video/mp4');
        self::assertSame(['0123', '456'], [$requests[1]['body'], $requests[2]['body']]);
        self::assertSame('video/mp4', $requests[0]['headers']['content-type']);
        self::assertStringContainsString('<Part><PartNumber>1</PartNumber><ETag>"e1"</ETag></Part><Part><PartNumber>2</PartNumber>', $requests[3]['body']);
    }

    public function testGetWholeOrARangeAndStreamed(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('whole file'),
            new MockResponse('whol', ['http_code' => 206]),
            new MockResponse('<Error><Code>InvalidRange</Code></Error>', ['http_code' => 416]),
            new MockResponse(['chunk 1, ', 'chunk 2']),
        ], $requests);
        self::assertSame('whole file', $client->get('my-bucket', 'docs/a.txt'));
        self::assertSame('whol', $client->get('my-bucket', 'docs/a.txt', 'bytes=0-3'));
        self::assertSame('bytes=0-3', $requests[1]['headers']['range']);
        self::assertStringContainsString('SignedHeaders=host;range;x-amz-content-sha256;x-amz-date', $requests[1]['headers']['authorization']);
        self::assertSame('', $client->get('my-bucket', 'empty.txt', 'bytes=0-3'), 'a range past the end');

        $stream = $client->getStream('my-bucket', 'docs/a.txt');
        self::assertSame('chunk 1, chunk 2', stream_get_contents($stream));
        fclose($stream);
        self::assertSame(['GET', 'GET', 'GET', 'GET'], array_column($requests, 'method'));
        self::assertSame('https://acc123.r2.cloudflarestorage.com/my-bucket/docs/a.txt', $requests[3]['url']);
    }

    public function testGetOfAMissingObjectAndBadRanges(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('<Error><Code>NoSuchKey</Code><Message>gone</Message></Error>', ['http_code' => 404])], $requests);
        try {
            $client->getStream('my-bucket', 'gone.txt');
            self::fail('expected an R2Exception');
        } catch (R2Exception $e) {
            self::assertSame(404, $e->status);
            self::assertSame('NoSuchKey', $e->errorCode);
        }
        $this->expectException(R2Exception::class);
        $client->get('my-bucket', 'a.txt', '0-10');
    }

    public function testSizeReadsTheHeadAnswer(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('', ['response_headers' => ['Content-Length' => '1234']]), new MockResponse('', ['http_code' => 404])], $requests);
        self::assertSame(1234, $client->size('my-bucket', 'a.bin'));
        self::assertNull($client->size('my-bucket', 'gone.bin'));
        self::assertSame(['HEAD', 'HEAD'], array_column($requests, 'method'));
    }

    public function testListKeysFollowsContinuationTokens(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('<?xml version="1.0"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>true</IsTruncated><Contents><Key>v/1/a.ts</Key></Contents><Contents><Key>v/1/b.ts</Key></Contents><NextContinuationToken>tok/1=</NextContinuationToken></ListBucketResult>'),
            new MockResponse('<?xml version="1.0"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>false</IsTruncated><Contents><Key>v/1/c &amp; d.ts</Key></Contents></ListBucketResult>'),
        ], $requests);
        self::assertSame(['v/1/a.ts', 'v/1/b.ts', 'v/1/c & d.ts'], iterator_to_array($client->listKeys('my-bucket', 'v/1/'), false));
        self::assertSame('https://acc123.r2.cloudflarestorage.com/my-bucket?list-type=2&prefix=v%2F1%2F', $requests[0]['url']);
        self::assertSame('https://acc123.r2.cloudflarestorage.com/my-bucket?continuation-token=tok%2F1%3D&list-type=2&prefix=v%2F1%2F', $requests[1]['url']);
    }

    public function testDeletePrefixDeletesInBatchesWithContentMd5(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>v/1/a.ts</Key></Contents><Contents><Key>v/1/b&amp;.ts</Key></Contents></ListBucketResult>'),
            new MockResponse('<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></DeleteResult>'),
        ], $requests);
        self::assertSame(2, $client->deletePrefix('my-bucket', 'v/1/'));

        [, $delete] = $requests;
        self::assertSame('POST', $delete['method']);
        self::assertSame('https://acc123.r2.cloudflarestorage.com/my-bucket?delete=', $delete['url']);
        self::assertStringContainsString('<Quiet>true</Quiet><Object><Key>v/1/a.ts</Key></Object><Object><Key>v/1/b&amp;.ts</Key></Object>', $delete['body']);
        self::assertSame(base64_encode(md5($delete['body'], true)), $delete['headers']['content-md5']);
        self::assertStringContainsString('SignedHeaders=content-length;content-md5;content-type;host;', $delete['headers']['authorization']);
    }

    public function testDeletePrefixReportsKeysItCouldNotDelete(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('<ListBucketResult><Contents><Key>v/a</Key></Contents></ListBucketResult>'),
            new MockResponse('<DeleteResult><Error><Key>v/a</Key><Code>AccessDenied</Code><Message>no</Message></Error></DeleteResult>'),
        ], $requests);
        try {
            $client->deletePrefix('my-bucket', 'v/');
            self::fail('expected an R2Exception');
        } catch (R2Exception $e) {
            self::assertSame('AccessDenied', $e->errorCode);
            self::assertStringContainsString('could not delete 1 of 1 objects (v/a: AccessDenied: no)', $e->getMessage());
        }
    }

    public function testDeletePrefixRefusesAnEmptyPrefix(): void
    {
        $requests = [];
        $this->expectException(R2Exception::class);
        $this->client([], $requests)->deletePrefix('my-bucket', '');
    }

    public function testBrowserMultipartUpload(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><UploadId>up/9+</UploadId></InitiateMultipartUploadResult>'),
            new MockResponse('<ListPartsResult><IsTruncated>true</IsTruncated><NextPartNumberMarker>2</NextPartNumberMarker><Part><PartNumber>2</PartNumber><ETag>"b"</ETag><Size>5</Size></Part><Part><PartNumber>1</PartNumber><ETag>"a"</ETag></Part></ListPartsResult>'),
            new MockResponse('<ListPartsResult><IsTruncated>false</IsTruncated><Part><PartNumber>3</PartNumber><ETag>"c"</ETag></Part></ListPartsResult>'),
            new MockResponse('<CompleteMultipartUploadResult/>'),
        ], $requests);

        $uploadId = $client->createMultipartUpload('my-bucket', 'in/film.mp4', 'video/mp4', ['Cache-Control' => 'private']);
        self::assertSame('up/9+', $uploadId);
        self::assertSame('https://acc123.r2.cloudflarestorage.com/my-bucket/in/film.mp4?uploads=', $requests[0]['url']);
        self::assertSame('video/mp4', $requests[0]['headers']['content-type']);
        self::assertSame('private', $requests[0]['headers']['cache-control']);

        $url = $client->presignedUploadPartUrl('my-bucket', 'in/film.mp4', $uploadId, 3, 7200);
        self::assertStringStartsWith('https://acc123.r2.cloudflarestorage.com/my-bucket/in/film.mp4?', $url);
        self::assertStringContainsString('partNumber=3', $url);
        self::assertStringContainsString('uploadId=up%2F9%2B', $url);
        self::assertStringContainsString('X-Amz-Expires=7200', $url);
        self::assertCount(1, $requests, 'presigning makes no request');

        $client->completeMultipartUpload('my-bucket', 'in/film.mp4', $uploadId); // parts read from storage
        self::assertSame('https://acc123.r2.cloudflarestorage.com/my-bucket/in/film.mp4?uploadId=up%2F9%2B', $requests[1]['url']);
        self::assertSame('https://acc123.r2.cloudflarestorage.com/my-bucket/in/film.mp4?part-number-marker=2&uploadId=up%2F9%2B', $requests[2]['url']);
        self::assertSame('POST', $requests[3]['method']);
        self::assertStringContainsString('<Part><PartNumber>1</PartNumber><ETag>"a"</ETag></Part><Part><PartNumber>2</PartNumber><ETag>"b"</ETag></Part><Part><PartNumber>3</PartNumber><ETag>"c"</ETag></Part></CompleteMultipartUpload>', $requests[3]['body']);
    }

    public function testListPartsReturnsNumbersAndEtagsInOrder(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('<ListPartsResult><Part><PartNumber>2</PartNumber><ETag>"b"</ETag></Part><Part><PartNumber>1</PartNumber><ETag>"a"</ETag></Part></ListPartsResult>')], $requests);
        self::assertSame([1 => '"a"', 2 => '"b"'], $client->listParts('my-bucket', 'in/x.bin', 'up-1'));
        self::assertSame('GET', $requests[0]['method']);
    }

    public function testCompletingCanFailWithAStatus200(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('<?xml version="1.0"?><Error><Code>InvalidPart</Code><Message>One or more of the specified parts could not be found.</Message></Error>')], $requests);
        try {
            $client->completeMultipartUpload('my-bucket', 'in/x.bin', 'up-1', [1 => '"a"']);
            self::fail('expected an R2Exception');
        } catch (R2Exception $e) {
            self::assertSame('InvalidPart', $e->errorCode);
            self::assertSame(200, $e->status);
        }
    }

    public function testCompletingWithoutPartsAndAborting(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('<ListPartsResult/>'), new MockResponse('<Error><Code>NoSuchUpload</Code></Error>', ['http_code' => 404])], $requests);
        try {
            $client->completeMultipartUpload('my-bucket', 'in/x.bin', 'up-1');
            self::fail('expected an R2Exception');
        } catch (R2Exception) {
            $this->addToAssertionCount(1);
        }
        $client->abortMultipartUpload('my-bucket', 'in/x.bin', 'up-1'); // unknown upload: fine
        self::assertSame('DELETE', $requests[1]['method']);
        self::assertStringEndsWith('/my-bucket/in/x.bin?uploadId=up-1', $requests[1]['url']);
    }

    public function testPartNumbersAreChecked(): void
    {
        $requests = [];
        $this->expectException(R2Exception::class);
        $this->client([], $requests)->presignedUploadPartUrl('my-bucket', 'a.bin', 'up-1', 10001);
    }

    public function testPresignEndpointIsUsedAndSignedForLinksOnly(): void
    {
        $requests = [];
        $config = new R2Config('http://minio:9000', 'AKID', 'SECRET', 'us-east-1', 'http://s3.app.local/');
        $client = $this->client([new MockResponse('')], $requests, config: $config);
        $client->delete('my-bucket', 'a.txt');
        self::assertSame('http://minio:9000/my-bucket/a.txt', $requests[0]['url'], 'the app talks to the endpoint');

        $url = $client->presignedGetUrl('my-bucket', 'a.txt', 60);
        self::assertStringStartsWith('http://s3.app.local/my-bucket/a.txt?', $url);
        $expected = (new Signer('AKID', 'SECRET', 'us-east-1'))->presignQuery('GET', 's3.app.local', '/my-bucket/a.txt', 60, new \DateTimeImmutable('2026-11-04T10:00:00Z'));
        self::assertSame('http://s3.app.local/my-bucket/a.txt?'.$expected, $url, 'signed for the browser-facing host');
        self::assertStringStartsWith('http://s3.app.local/', $client->presignedUploadPartUrl('my-bucket', 'a.txt', 'up-1', 1));
        self::assertSame('s3.app.local', $config->presignHost());
        self::assertSame('http://minio:9000', (new R2Config('http://minio:9000', 'a', 'b', 'us-east-1', ''))->presignEndpoint, 'defaults to the endpoint');
    }

    public function testContentDispositionForAnyFileName(): void
    {
        self::assertSame('attachment; filename="report.pdf"', ContentDisposition::attachment('report.pdf'));
        self::assertSame("attachment; filename=\"Bulletin de No_l.pdf\"; filename*=UTF-8''Bulletin%20de%20No%C3%ABl.pdf", ContentDisposition::attachment('Bulletin de Noël.pdf'));
        self::assertSame("inline; filename=\"_a_b_.txt\"; filename*=UTF-8''%22a%5Cb%22.txt", ContentDisposition::inline("\"a\\b\".txt\r\n"));
        self::assertSame('attachment', ContentDisposition::attachment(''));

        $requests = [];
        $url = $this->client([], $requests)->presignedGetUrl('my-bucket', 'a.pdf', 60, ContentDisposition::attachment('été.pdf'));
        self::assertStringContainsString('response-content-disposition=attachment%3B%20filename%3D%22_t_.pdf%22%3B%20filename%2A%3DUTF-8%27%27%25C3%25A9t%25C3%25A9.pdf', $url);
    }
}
