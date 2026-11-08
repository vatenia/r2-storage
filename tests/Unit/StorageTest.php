<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Vatenia\R2Storage\R2Client;
use Vatenia\R2Storage\R2Config;
use Vatenia\R2Storage\Storage\DirectUploads;
use Vatenia\R2Storage\Storage\FileNotFoundException;
use Vatenia\R2Storage\Storage\Key;
use Vatenia\R2Storage\Storage\LocalStorage;
use Vatenia\R2Storage\Storage\MemoryStorage;
use Vatenia\R2Storage\Storage\R2Storage;
use Vatenia\R2Storage\Storage\StorageFactory;
use Vatenia\R2Storage\Storage\StorageInterface;
use Vatenia\R2Storage\Storage\StoreOptions;

final class StorageTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/r2-storage-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    private function source(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($path, $contents);

        return $path;
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'parent' => ['../etc/passwd'];
        yield 'inner parent' => ['a/../../b'];
        yield 'absolute' => ['/abs.jpg'];
        yield 'trailing slash' => ['folder/'];
        yield 'empty segment' => ['a//b'];
        yield 'backslash' => ['a\\b.jpg'];
        yield 'space' => ['my photo.jpg'];
        yield 'empty' => [''];
        yield 'dot' => ['./a.jpg'];
        yield 'hidden file' => ['.htaccess'];
        yield 'hidden file in a folder' => ['uploads/.user.ini'];
    }

    #[DataProvider('invalidKeys')]
    public function testKeysCannotEscapeOrSurprise(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Key::assertValid($key);
    }

    public function testValidKeys(): void
    {
        Key::assertValid('projects/2026/site-v2_1.jpg');
        Key::assertValid('a/b/c.d.e.webp');
        $this->addToAssertionCount(2);
    }

    public function testLocalStorageStoresLinksAndDeletes(): void
    {
        $storage = new LocalStorage($this->dir, '/uploads/');
        $src = $this->source((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true)); // 1×1 PNG
        $file = $storage->store($src, 'projects/logo.png');
        unlink($src);

        self::assertSame('local', $file->driver);
        self::assertSame('image/png', $file->contentType, 'detected from the content');
        self::assertSame('/uploads/projects/logo.png', $file->publicUrl);
        self::assertSame('/uploads/projects/logo.png', $storage->url('projects/logo.png'));
        self::assertFileExists($this->dir.'/projects/logo.png');
        self::assertTrue($storage->exists('projects/logo.png'));

        $storage->delete('projects/logo.png');
        $storage->delete('projects/logo.png'); // already gone: fine
        self::assertFalse($storage->exists('projects/logo.png'));
    }

    public function testLocalStorageRefusesKeysOutsideItsDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LocalStorage($this->dir))->store($this->source('x'), '../outside.txt');
    }

    public function testR2StoragePublicAndPrivateLinks(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $lines = $options['headers'] ?? [];
            $requests[] = [$method, $url, array_map(static fn (mixed $l): string => \is_string($l) ? strtolower($l) : '', \is_array($lines) ? $lines : [])];

            return new MockResponse('', ['http_code' => 200]);
        });
        $client = new R2Client($http, new R2Config('https://acc.r2.cloudflarestorage.com', 'AKID', 'SECRET'));

        $public = new R2Storage($client, 'site-media', 'https://media.example.com/');
        $file = $public->store($this->source('GIF89a'), 'projects/a.gif', new StoreOptions(cacheControl: 'public, max-age=31536000, immutable'));
        self::assertSame('r2', $file->driver);
        self::assertSame('site-media', $file->bucket);
        self::assertSame('image/gif', $file->contentType);
        self::assertSame('https://media.example.com/projects/a.gif', $file->publicUrl);
        self::assertSame('https://media.example.com/projects/a.gif', $public->url('projects/a.gif'));
        self::assertContains('cache-control: public, max-age=31536000, immutable', $requests[0][2]);

        $private = new R2Storage($client, 'site-private');
        self::assertNull($private->store($this->source('x'), 'docs/a.txt')->publicUrl);
        self::assertStringContainsString('X-Amz-Signature=', $private->url('docs/a.txt', 60));
    }

    public function testFactoryPicksTheDriver(): void
    {
        $client = new R2Client(new MockHttpClient(), new R2Config('', '', ''));
        self::assertInstanceOf(LocalStorage::class, StorageFactory::create('local', $client, '', null, $this->dir));
        self::assertInstanceOf(R2Storage::class, StorageFactory::create(' R2 ', $client, 'bucket-1', null));
        self::assertInstanceOf(MemoryStorage::class, StorageFactory::create('memory', $client));
    }

    public function testOnlyR2StoragesHaveDirectUploads(): void
    {
        $client = new R2Client(new MockHttpClient(), new R2Config('', '', ''));
        $r2 = new R2Storage($client, 'bucket-1');
        self::assertSame($r2, StorageFactory::directUploads($r2));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The storage "videos" uses the "memory" driver, which has no direct (browser) uploads');
        StorageFactory::directUploads(new MemoryStorage(), 'videos');
    }

    /** @return iterable<string, array{\Closure(string): StorageInterface}> */
    public static function storages(): iterable
    {
        yield 'local' => [static fn (string $dir): StorageInterface => new LocalStorage($dir, '/files')];
        yield 'memory' => [static fn (string $dir): StorageInterface => new MemoryStorage('/files')];
    }

    /** @param \Closure(string): StorageInterface $make */
    #[DataProvider('storages')]
    public function testPutReadAndSize(\Closure $make): void
    {
        $storage = $make($this->dir);
        $file = $storage->put('notes/a.txt', 'hello');
        self::assertSame(5, $file->size);
        self::assertSame('text/plain', $file->contentType, 'detected');
        self::assertSame('/files/notes/a.txt', $file->publicUrl);
        self::assertSame('hello', $storage->read('notes/a.txt'));
        self::assertSame(5, $storage->size('notes/a.txt'));
        self::assertNull($storage->size('notes/none.txt'));
        self::assertSame('/files/notes/a.txt', $storage->downloadUrl('notes/a.txt', 'Été.txt'));

        // from a stream: the whole of it, given content type, stream left open
        $stream = fopen('php://temp', 'w+');
        \assert(false !== $stream);
        fwrite($stream, 'from a stream');
        $file = $storage->put('notes/b.bin', $stream, new StoreOptions('application/pdf'));
        self::assertSame('stream', get_resource_type($stream), 'left open');
        fclose($stream);
        self::assertSame(13, $file->size);
        self::assertSame('application/pdf', $file->contentType);
        self::assertSame('from a stream', $storage->read('notes/b.bin'));

        $read = $storage->readStream('notes/b.bin');
        self::assertSame('from a stream', stream_get_contents($read));
        fclose($read);

        $storage->put('notes/a.txt', 'replaced');
        self::assertSame(8, $storage->size('notes/a.txt'));
    }

    /** @param \Closure(string): StorageInterface $make */
    #[DataProvider('storages')]
    public function testReadingAMissingFile(\Closure $make): void
    {
        $storage = $make($this->dir);
        try {
            $storage->read('none.txt');
            self::fail('expected a FileNotFoundException');
        } catch (FileNotFoundException $e) {
            self::assertSame('none.txt', $e->key);
        }
        $this->expectException(FileNotFoundException::class);
        $storage->readStream('none.txt');
    }

    /** @param \Closure(string): StorageInterface $make */
    #[DataProvider('storages')]
    public function testDeletePrefix(\Closure $make): void
    {
        $storage = $make($this->dir);
        foreach (['videos/41/a.ts', 'videos/42/hls/v0/seg_1.ts', 'videos/42/hls/master.m3u8', 'videos/420/x.ts', 'videos/43.txt'] as $key) {
            $storage->put($key, 'x');
        }
        self::assertSame(2, $storage->deletePrefix('videos/42/'));
        self::assertFalse($storage->exists('videos/42/hls/master.m3u8'));
        self::assertTrue($storage->exists('videos/420/x.ts'), 'a folder prefix stops at the "/"');
        self::assertSame(0, $storage->deletePrefix('videos/42/'));
        self::assertSame(1, $storage->deletePrefix('videos/42'), 'a plain prefix matches the start of names');
        self::assertTrue($storage->exists('videos/41/a.ts'));
        self::assertTrue($storage->exists('videos/43.txt'));
        if ($storage instanceof LocalStorage) {
            self::assertDirectoryDoesNotExist($this->dir.'/videos/42', 'emptied folders are removed');
            self::assertDirectoryDoesNotExist($this->dir.'/videos/420');
        }
        if ($storage instanceof MemoryStorage) {
            self::assertSame(['videos/41/a.ts', 'videos/43.txt'], $storage->keys());
        }
    }

    /** @param \Closure(string): StorageInterface $make */
    #[DataProvider('storages')]
    public function testDeletePrefixRefusesEmptyOrEscapingPrefixes(\Closure $make): void
    {
        $storage = $make($this->dir);
        foreach (['', '/', '../', 'a/../../'] as $prefix) {
            try {
                $storage->deletePrefix($prefix);
                self::fail("\"{$prefix}\" should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testR2StorageReadsSizesAndDirectUploads(): void
    {
        $requests = [];
        $responses = [
            new MockResponse('', ['http_code' => 200]), // put (stream)
            new MockResponse('<Error><Code>NoSuchKey</Code></Error>', ['http_code' => 404]), // read
            new MockResponse('%PDF-1.7', ['http_code' => 206]), // firstBytes
            new MockResponse('<InitiateMultipartUploadResult><UploadId>up-1</UploadId></InitiateMultipartUploadResult>'),
            new MockResponse('<ListPartsResult><Part><PartNumber>1</PartNumber><ETag>"a"</ETag></Part></ListPartsResult>'),
            new MockResponse('<CompleteMultipartUploadResult/>'),
            new MockResponse('', ['http_code' => 204]), // abort
        ];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $lines = $options['headers'] ?? [];
            $requests[] = [$method, $url, array_map(static fn (mixed $l): string => \is_string($l) ? strtolower($l) : '', \is_array($lines) ? $lines : [])];
            $response = array_shift($responses);
            self::assertNotNull($response);

            return $response;
        });
        $storage = new R2Storage(new R2Client($http, new R2Config('https://acc.r2.cloudflarestorage.com', 'AKID', 'SECRET')), 'school-private');

        $stream = fopen('php://temp', 'w+');
        \assert(false !== $stream);
        fwrite($stream, "GIF89a\x01\x00");
        $file = $storage->put('logos/a.gif', $stream);
        fclose($stream);
        self::assertSame('image/gif', $file->contentType, 'detected from the first bytes');
        self::assertSame(8, $file->size);

        try {
            $storage->read('gone.txt');
            self::fail('expected a FileNotFoundException');
        } catch (FileNotFoundException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame('%PDF-1.7', $storage->firstBytes('in/a.pdf', 8));
        self::assertContains('range: bytes=0-7', $requests[2][2]);

        self::assertSame('up-1', $storage->startUpload('in/a.pdf', new StoreOptions('application/pdf')));
        self::assertContains('content-type: application/pdf', $requests[3][2]);
        self::assertStringContainsString('partNumber=2&uploadId=up-1', $storage->uploadPartUrl('in/a.pdf', 'up-1', 2));
        $storage->completeUpload('in/a.pdf', 'up-1');
        $storage->abortUpload('in/b.pdf', 'up-2');
        self::assertSame(['PUT', 'GET', 'GET', 'POST', 'GET', 'POST', 'DELETE'], array_column($requests, 0));

        $download = $storage->downloadUrl('in/a.pdf', 'Relevé.pdf', 60);
        self::assertStringContainsString('response-content-disposition=attachment%3B%20filename%3D%22Relev_.pdf%22', $download);
        self::assertInstanceOf(DirectUploads::class, $storage);
    }

    public function testFactoryRejectsUnknownDriversAndR2WithoutBucket(): void
    {
        $client = new R2Client(new MockHttpClient(), new R2Config('', '', ''));
        foreach ([['s3', 'b-1'], ['r2', '']] as [$driver, $bucket]) {
            try {
                StorageFactory::create($driver, $client, $bucket);
                self::fail("{$driver} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
