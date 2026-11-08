# vatenia/r2-storage

File storage on **Cloudflare R2** (its S3 API) for PHP and Symfony, without the AWS SDK.
The same interface stores files on local disk in development, so switching is a setting.

- Signed requests (AWS Signature V4, checked against AWS's published test values).
- Upload from a file, a string or a stream (multipart for large files, retries on network errors, 5xx and 429).
- Read (whole, a byte range, or streamed through a temporary file), size, exists, delete, list and delete by prefix.
- Public storages (permanent links on the bucket's custom domain) and private ones (presigned links that expire),
  download links with any file name (`filename*=UTF-8''…`).
- Presigned **upload** links, and **browser multipart uploads** (one presigned link per part), so a browser
  sends a file of any size straight to R2.
- A presign endpoint: links for the address browsers reach (e.g. MinIO in Docker during development).
- Drivers `r2`, `local` (development) and `memory` (tests) behind one interface.
- Safe keys: no `..`, no leading `/`, no hidden files; a key can't leave a local storage directory.
- PHP ≥ 8.1, Symfony 6.4 / 7 / 8 (the bundle is optional). Tested against VersityGW (S3-compatible) as well.

## Install

From [Packagist](https://packagist.org/packages/vatenia/r2-storage):

```sh
composer require vatenia/r2-storage symfony/http-client
```

(To work on the library next to a project, add `{ "type": "path", "url": "../r2-storage" }` to that project's `repositories` for a while.)

## Symfony

`config/packages/vatenia_r2_storage.yaml`:

```yaml
vatenia_r2_storage:
    r2:
        endpoint: '%env(R2_ENDPOINT)%'                 # https://<account-id>.r2.cloudflarestorage.com
        access_key_id: '%env(R2_ACCESS_KEY_ID)%'
        secret_access_key: '%env(R2_SECRET_ACCESS_KEY)%'
    storages:
        public:                                         # first one = the default StorageInterface
            driver: '%env(STORAGE_DRIVER)%'             # local | memory (tests) | r2
            bucket: '%env(R2_PUBLIC_BUCKET)%'
            public_url: '%env(R2_PUBLIC_URL)%'          # e.g. https://media.example.com
            directory: '%kernel.project_dir%/public/uploads'   # local driver
            local_url: /uploads
        private:
            driver: '%env(STORAGE_DRIVER)%'
            bucket: '%env(R2_PRIVATE_BUCKET)%'          # no public_url: links are presigned
            directory: '%kernel.project_dir%/var/private-uploads'
```

`.env` (committed, no secrets):

```dotenv
STORAGE_DRIVER=local
R2_ENDPOINT=
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_PUBLIC_BUCKET=
R2_PUBLIC_URL=
```

Real keys go in `.env.local` or `bin/console secrets:set` on the server, never in Git.
Use an R2 API token limited to the project's buckets ("Object Read & Write").

```php
use Vatenia\R2Storage\Storage\StorageInterface;
use Vatenia\R2Storage\Storage\StoreOptions;

public function __construct(private StorageInterface $publicStorage, private StorageInterface $privateStorage) {}

$file = $this->publicStorage->store($uploaded->getPathname(), 'projects/'.$name, new StoreOptions(cacheControl: 'public, max-age=31536000, immutable'));
$file->publicUrl;                                   // https://media.example.com/projects/…
$this->privateStorage->url('scores/a.pdf', 300);    // presigned, 5 minutes
$this->publicStorage->delete('projects/'.$name);
```

### The storage interface

Every driver (`r2`, `local`, `memory`) implements `StorageInterface`:

```php
$storage->store($path, $key, ?StoreOptions);          // copy a file → StoredFile (driver, key, size, contentType, bucket, publicUrl)
$storage->put($key, $stringOrStream, ?StoreOptions);  // a string or a whole open stream (rewound first, left open)
$storage->read($key);                                 // string; FileNotFoundException when missing
$storage->readStream($key);                           // resource at its start, for the caller to close
$storage->size($key);                                 // ?int, null when missing
$storage->exists($key);
$storage->delete($key);                               // missing: not an error
$storage->deletePrefix('videos/42/');                 // number deleted; "" is refused
$storage->url($key, 300);                             // public link, or presigned (private R2)
$storage->downloadUrl($key, 'Relevé de notes.pdf', 300);   // browser saves it under that name (R2; local/memory: url())
```

The content type is detected from the content unless `StoreOptions` gives it. Keys follow strict
rules (letters, digits, `.`, `_`, `-`, `/`; no segment starting with `.`), the same on every driver.

### Direct uploads from the browser

A single `PUT`: inject `Vatenia\R2Storage\R2Client` and hand out `presignedPutUrl($bucket, $key, 300)`.

Large files, in parts (16 MiB parts, a 2 GB video…): the `r2` driver also implements
`DirectUploads`. Inject it by the storage's name, `DirectUploads $privateStorage`:

```php
use Vatenia\R2Storage\Storage\DirectUploads;

public function __construct(private DirectUploads $privateStorage) {}

// 1. start: the app picks the key, keeps the upload id, hands out one link per part
$uploadId = $this->privateStorage->startUpload($key, new StoreOptions('video/mp4'));
$partSize = 16 * 1024 * 1024;                       // ≥ DirectUploads::MIN_PART_SIZE (5 MiB), except the last
foreach (range(1, (int) ceil($size / $partSize)) as $n) {
    $links[] = ['number' => $n, 'url' => $this->privateStorage->uploadPartUrl($key, $uploadId, $n, 6 * 3600)];
}
// 2. the browser PUTs each slice of the file to its link (in parallel, retrying a part if needed)
// 3. complete: the part ETags are read from storage, not taken from the browser
$this->privateStorage->completeUpload($key, $uploadId);
// 4. check what really arrived
if ($this->privateStorage->size($key) !== $size || !$this->accepts((new \finfo(\FILEINFO_MIME_TYPE))->buffer($this->privateStorage->firstBytes($key)))) {
    $this->privateStorage->delete($key);
}
// abandoned: $this->privateStorage->abortUpload($key, $uploadId);  (an unknown upload is not an error)
```

The bucket needs a CORS rule allowing `PUT` from the site's origin (exposing `ETag` isn't needed:
the app reads the ETags itself). The local and memory drivers have no address a browser could
send to, so they don't implement `DirectUploads`: in Symfony, building a service that asks for
`DirectUploads $xStorage` fails with a clear message when that storage's driver isn't `r2`. Use the
`r2` driver against MinIO or VersityGW in development (below). The same operations are on
`R2Client` (`createMultipartUpload`, `presignedUploadPartUrl`, `listParts`, `completeMultipartUpload`,
`abortMultipartUpload`) for other uses.

### Example: a school platform (private + public storages, MinIO in development)

```yaml
vatenia_r2_storage:
    r2:
        endpoint: '%env(S3_ENDPOINT)%'                       # dev: http://minio:9000 (inside Docker)
        presign_endpoint: '%env(default::S3_BROWSER_ENDPOINT)%'   # dev: http://s3.app.local (what browsers reach)
        access_key_id: '%env(S3_KEY)%'
        secret_access_key: '%env(S3_SECRET)%'
        region: '%env(S3_REGION)%'                           # auto on R2, us-east-1 on MinIO
    storages:
        private:                                             # documents, course files, videos
            driver: '%env(STORAGE_DRIVER)%'                  # r2 (MinIO in dev, R2 in prod) | memory in tests
            bucket: '%env(S3_BUCKET)%'
        public:                                              # logos, photos
            driver: '%env(STORAGE_DRIVER)%'
            bucket: '%env(S3_PUBLIC_BUCKET)%'
            public_url: '%env(S3_PUBLIC_ENDPOINT)%'

when@test:
    vatenia_r2_storage:
        storages:
            private: { driver: memory }
            public: { driver: memory }
```

```php
// (the upload service asks for DirectUploads $privateStorage instead: the same storage, see above)
public function __construct(private StorageInterface $privateStorage, private StorageInterface $publicStorage) {}

$this->publicStorage->put("schools/$id/logo.png", $stream, new StoreOptions('image/png'))->publicUrl;
$stream = $this->privateStorage->readStream($file->getPath());          // stream a document after a permission check
$this->privateStorage->downloadUrl($file->getPath(), $file->getOriginalName(), 600);   // or hand out a link
$this->privateStorage->put("videos/$uuid/hls/v0/seg_0.ts", $segment, new StoreOptions('video/mp2t'));
$this->privateStorage->read("videos/$uuid/hls/master.m3u8");
$this->privateStorage->deletePrefix("videos/$uuid/");                   // a video and all its renditions
```

Presigned links (downloads, part uploads) are built and signed for `presign_endpoint`, so a
browser outside Docker can use them, while the app keeps talking to `endpoint`. Without
`presign_endpoint` both are the same (production on R2).

## Without Symfony

```php
$client = new R2Client(HttpClient::create(), R2Config::forAccount($accountId, $keyId, $secret));
$storage = new R2Storage($client, 'my-bucket', 'https://media.example.com');

// MinIO in development: path-style endpoint, region us-east-1, links for the browser-facing address
$config = new R2Config('http://minio:9000', $key, $secret, 'us-east-1', presignEndpoint: 'http://s3.app.local');

// the client directly
$client->get($bucket, $key, 'bytes=0-4095');     // string (a range past the end: "")
$client->getStream($bucket, $key);               // resource (php://temp: large bodies spill to disk)
$client->size($bucket, $key);                    // ?int
$client->putStream($bucket, $key, $stream, 'video/mp4');
foreach ($client->listKeys($bucket, 'videos/42/') as $key) { … }   // ListObjectsV2, every page
$client->deletePrefix($bucket, 'videos/42/');    // DeleteObjects, 1,000 keys per request
$client->presignedGetUrl($bucket, $key, 600, ContentDisposition::attachment('Leçon 1.mp4'));
```

## Development

```sh
composer install
composer qa                 # php-cs-fixer, PHPStan (max), Psalm (level 1), unit tests
docker compose up -d        # VersityGW, an S3-compatible server, for the integration tests
R2_TEST_ENDPOINT=http://127.0.0.1:19000 R2_TEST_ACCESS_KEY_ID=r2-storage-test \
R2_TEST_SECRET_ACCESS_KEY=r2-storage-test-secret R2_TEST_REGION=us-east-1 \
vendor/bin/phpunit --testsuite integration
```

To run the integration tests against a real R2 test bucket instead: `R2_TEST_ENDPOINT` = the
account endpoint, `R2_TEST_REGION=auto`, `R2_TEST_BUCKET` = an existing, empty test bucket.

GitHub Actions (`.github/workflows/ci.yaml`) runs `composer qa` on each push and pull request, and
the unit and integration tests on PHP 8.1 to 8.5 (on 8.1 also with the oldest allowed dependencies).

Releases follow semantic versioning (Git tags `v0.1.0`…); see `CHANGELOG.md`. Packagist picks up new tags through the GitHub hook.

## License

MIT, see `LICENSE`.
