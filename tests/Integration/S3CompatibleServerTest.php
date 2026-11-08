<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Vatenia\R2Storage\ContentDisposition;
use Vatenia\R2Storage\R2Client;
use Vatenia\R2Storage\R2Config;
use Vatenia\R2Storage\Signer;
use Vatenia\R2Storage\Storage\FileNotFoundException;
use Vatenia\R2Storage\Storage\R2Storage;
use Vatenia\R2Storage\Storage\StoreOptions;

/**
 * Against a real S3-compatible server (VersityGW from compose.yaml, or an R2 test bucket):
 *   docker compose up -d
 *   R2_TEST_ENDPOINT=http://127.0.0.1:19000 R2_TEST_ACCESS_KEY_ID=r2-storage-test \
 *   R2_TEST_SECRET_ACCESS_KEY=r2-storage-test-secret R2_TEST_REGION=us-east-1 vendor/bin/phpunit --testsuite integration
 * Skipped when R2_TEST_ENDPOINT isn't set.
 */
final class S3CompatibleServerTest extends TestCase
{
    private HttpClientInterface $http;
    private R2Config $config;
    private string $bucket = '';
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $endpoint = getenv('R2_TEST_ENDPOINT');
        if (!\is_string($endpoint) || '' === $endpoint) {
            self::markTestSkipped('R2_TEST_ENDPOINT is not set (see the class comment).');
        }
        $this->http = HttpClient::create();
        $this->config = new R2Config($endpoint, (string) getenv('R2_TEST_ACCESS_KEY_ID'), (string) getenv('R2_TEST_SECRET_ACCESS_KEY'), (string) (getenv('R2_TEST_REGION') ?: 'auto'));
        $this->bucket = (string) (getenv('R2_TEST_BUCKET') ?: 'r2-storage-test-'.bin2hex(random_bytes(3)));
        if (!getenv('R2_TEST_BUCKET')) {
            $this->bucketRequest('PUT');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        if ('' !== $this->bucket && !getenv('R2_TEST_BUCKET')) {
            $this->bucketRequest('DELETE');
        }
    }

    /** Create or delete the test bucket (not part of the library: R2 buckets are made in the dashboard). */
    private function bucketRequest(string $method): void
    {
        $signer = new Signer($this->config->accessKeyId, $this->config->secretAccessKey, $this->config->region);
        $hash = hash('sha256', '');
        $headers = $signer->signRequest($method, '/'.$this->bucket, [], ['host' => $this->config->host(), 'x-amz-content-sha256' => $hash], $hash, new \DateTimeImmutable());
        unset($headers['host']);
        $status = $this->http->request($method, $this->config->endpoint.'/'.$this->bucket, ['headers' => $headers])->getStatusCode();
        self::assertLessThan(300, $status, "{$method} bucket");
    }

    private function file(int $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'r2i');
        $this->files[] = $path;
        $handle = fopen($path, 'w');
        \assert(false !== $handle);
        for ($written = 0; $written < $bytes; $written += 65536) {
            fwrite($handle, random_bytes(max(1, min(65536, $bytes - $written))));
        }
        fclose($handle);

        return $path;
    }

    public function testFullCycle(): void
    {
        $client = new R2Client($this->http, $this->config);
        $storage = new R2Storage($client, $this->bucket);

        // upload, check, presigned download (content and headers come back), delete
        $src = $this->file(70_000);
        $stored = $storage->store($src, 'projects/photo-1.bin', new StoreOptions('image/webp', 'public, max-age=31536000, immutable'));
        self::assertSame(70_000, $stored->size);
        self::assertTrue($storage->exists('projects/photo-1.bin'));

        $download = $this->http->request('GET', $storage->url('projects/photo-1.bin', 120));
        self::assertSame(200, $download->getStatusCode());
        self::assertSame((string) file_get_contents($src), $download->getContent());
        self::assertSame('image/webp', $download->getHeaders()['content-type'][0]);
        self::assertSame('public, max-age=31536000, immutable', $download->getHeaders()['cache-control'][0]);

        $attachment = $this->http->request('GET', $client->presignedGetUrl($this->bucket, 'projects/photo-1.bin', 120, 'attachment; filename="photo.webp"'));
        self::assertSame('attachment; filename="photo.webp"', $attachment->getHeaders()['content-disposition'][0]);

        $storage->delete('projects/photo-1.bin');
        self::assertFalse($storage->exists('projects/photo-1.bin'));
        $storage->delete('projects/photo-1.bin'); // already gone: fine

        // a tampered link is refused
        $tampered = preg_replace('/X-Amz-Signature=[0-9a-f]{4}/', 'X-Amz-Signature=0000', $client->presignedGetUrl($this->bucket, 'projects/photo-1.bin'));
        self::assertSame(403, $this->http->request('GET', (string) $tampered)->getStatusCode());
    }

    public function testDirectUploadWithAPresignedPutLink(): void
    {
        $client = new R2Client($this->http, $this->config);
        $status = $this->http->request('PUT', $client->presignedPutUrl($this->bucket, 'inbox/from-phone.txt', 120), ['body' => 'sent by the phone'])->getStatusCode();
        self::assertSame(200, $status);
        self::assertTrue($client->exists($this->bucket, 'inbox/from-phone.txt'));
        $client->delete($this->bucket, 'inbox/from-phone.txt');
    }

    public function testLargeFileInParts(): void
    {
        // 12 MiB in 5 MiB parts (the S3 minimum) with a 6 MiB threshold
        $client = new R2Client($this->http, $this->config, null, 2, 6 * 1024 * 1024, 5 * 1024 * 1024);
        $src = $this->file(12 * 1024 * 1024);
        $client->putFile($this->bucket, 'big/recording.bin', $src, 'audio/mpeg');

        $download = $this->http->request('GET', $client->presignedGetUrl($this->bucket, 'big/recording.bin', 120));
        self::assertSame(hash_file('sha256', $src), hash('sha256', $download->getContent()));
        $client->delete($this->bucket, 'big/recording.bin');
    }

    public function testBrowserMultipartUploadThroughPresignedPartLinks(): void
    {
        $storage = new R2Storage(new R2Client($this->http, $this->config), $this->bucket);
        $src = $this->file(5 * 1024 * 1024 + 1000); // a 5 MiB part (the minimum) and a last, small one
        $contents = (string) file_get_contents($src);

        $uploadId = $storage->startUpload('in/film.bin', new StoreOptions('video/mp4'));
        foreach ([1 => substr($contents, 0, 5 * 1024 * 1024), 2 => substr($contents, 5 * 1024 * 1024)] as $number => $part) {
            // as a browser does: a plain PUT, no credentials
            $response = $this->http->request('PUT', $storage->uploadPartUrl('in/film.bin', $uploadId, $number, 300), ['body' => $part]);
            self::assertSame(200, $response->getStatusCode());
        }
        $parts = $storage->client()->listParts($this->bucket, 'in/film.bin', $uploadId);
        self::assertSame([1, 2], array_keys($parts));
        $storage->completeUpload('in/film.bin', $uploadId);

        self::assertSame(\strlen($contents), $storage->size('in/film.bin'));
        self::assertSame(substr($contents, 0, 4096), $storage->firstBytes('in/film.bin'));
        self::assertSame(substr($contents, 100, 50), $storage->client()->get($this->bucket, 'in/film.bin', 'bytes=100-149'));
        $stream = $storage->readStream('in/film.bin');
        self::assertSame(hash('sha256', $contents), hash('sha256', (string) stream_get_contents($stream)));
        fclose($stream);

        $download = $this->http->request('GET', $storage->downloadUrl('in/film.bin', 'Leçon 1.mp4', 120));
        self::assertSame(200, $download->getStatusCode());
        self::assertSame(ContentDisposition::attachment('Leçon 1.mp4'), $download->getHeaders()['content-disposition'][0]);
        self::assertSame('video/mp4', $download->getHeaders()['content-type'][0]);

        $storage->delete('in/film.bin');
        self::assertNull($storage->size('in/film.bin'));
    }

    public function testAbortedUploadAndMissingFiles(): void
    {
        $storage = new R2Storage(new R2Client($this->http, $this->config), $this->bucket);
        $uploadId = $storage->startUpload('in/dropped.bin');
        $this->http->request('PUT', $storage->uploadPartUrl('in/dropped.bin', $uploadId, 1), ['body' => 'part'])->getStatusCode();
        $storage->abortUpload('in/dropped.bin', $uploadId);
        $storage->abortUpload('in/dropped.bin', $uploadId); // already gone: fine
        self::assertFalse($storage->exists('in/dropped.bin'));

        try {
            $storage->read('in/dropped.bin');
            self::fail('expected a FileNotFoundException');
        } catch (FileNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(FileNotFoundException::class);
        $storage->readStream('in/dropped.bin');
    }

    public function testPutListAndDeletePrefix(): void
    {
        $client = new R2Client($this->http, $this->config);
        $storage = new R2Storage($client, $this->bucket);
        $storage->put('videos/7/hls/master.m3u8', "#EXTM3U\n", new StoreOptions('application/vnd.apple.mpegurl'));
        $stream = fopen('php://temp', 'w+');
        \assert(false !== $stream);
        fwrite($stream, str_repeat('ts', 1000));
        self::assertSame(2000, $storage->put('videos/7/hls/v0/seg_0.ts', $stream, new StoreOptions('video/mp2t'))->size);
        fclose($stream);
        $storage->put('videos/70/other.ts', 'x');

        self::assertSame("#EXTM3U\n", $storage->read('videos/7/hls/master.m3u8'));
        self::assertSame(['videos/7/hls/master.m3u8', 'videos/7/hls/v0/seg_0.ts'], iterator_to_array($client->listKeys($this->bucket, 'videos/7/'), false));
        self::assertSame(2, $storage->deletePrefix('videos/7/'));
        self::assertSame([], iterator_to_array($client->listKeys($this->bucket, 'videos/7/'), false));
        self::assertSame(['videos/70/other.ts'], iterator_to_array($client->listKeys($this->bucket, 'videos/'), false));
        self::assertSame(1, $storage->deletePrefix('videos/'));
    }

    public function testLinksForAPresignEndpoint(): void
    {
        // The app reaches the server at 127.0.0.1, "browsers" at localhost: links are signed for the latter.
        if ('127.0.0.1' !== parse_url($this->config->endpoint, \PHP_URL_HOST)) {
            self::markTestSkipped('Needs an endpoint on 127.0.0.1 (the local VersityGW).');
        }
        $browser = str_replace('://127.0.0.1', '://localhost', $this->config->endpoint);
        $config = new R2Config($this->config->endpoint, $this->config->accessKeyId, $this->config->secretAccessKey, $this->config->region, $browser);
        $client = new R2Client($this->http, $config);
        $client->putContents($this->bucket, 'docs/a.txt', 'for the browser', 'text/plain');

        $url = $client->presignedGetUrl($this->bucket, 'docs/a.txt', 120);
        self::assertStringStartsWith($browser.'/', $url);
        self::assertSame('for the browser', $this->http->request('GET', $url)->getContent());
        // the same link at the other address isn't valid: the host is signed
        self::assertSame(403, $this->http->request('GET', str_replace($browser, $this->config->endpoint, $url))->getStatusCode());
        $client->delete($this->bucket, 'docs/a.txt');
    }
}
