# Changelog

## 0.3.0

The package is renamed, with nothing else changed: the company behind it was renamed from Elviweb to
Vatenia.

- Composer package `elviweb/r2-storage` → **`vatenia/r2-storage`**; repository
  github.com/vatenia/r2-storage.
- Namespace `Elviweb\R2Storage` → **`Vatenia\R2Storage`**; bundle class `ElviwebR2StorageBundle` →
  **`VateniaR2StorageBundle`**.
- Configuration key `elviweb_r2_storage` → **`vatenia_r2_storage`** (rename
  `config/packages/elviweb_r2_storage.yaml` and its first line).

**BC break**: to upgrade, `composer remove elviweb/r2-storage && composer require vatenia/r2-storage`,
then replace `Elviweb\R2Storage` with `Vatenia\R2Storage` in your code and `config/bundles.php`, and
rename the configuration key. `elviweb/r2-storage` gets no further releases.

## 0.2.0

Everything an app needs to drop Flysystem + AsyncAws S3 for its buckets.

- `R2Client`: `get()` (with an optional byte range), `getStream()` (the body goes to php://temp as it
  arrives), `size()`, `putStream()` (any stream; one that can't be rewound is copied first; multipart
  above the threshold), `listKeys()` (ListObjectsV2, every page), `deletePrefix()` (DeleteObjects,
  1,000 keys per request, with Content-MD5).
- Browser multipart uploads on `R2Client`: `createMultipartUpload()`, `presignedUploadPartUrl()`,
  `listParts()` (every page), `completeMultipartUpload()` (parts read from storage when not given;
  a 200 answer with an `<Error>` is an error), `abortMultipartUpload()` (unknown upload: not an error).
  The internal multipart upload uses them.
- `R2Config` presign endpoint (5th argument, bundle `r2.presign_endpoint`): presigned links are
  built and signed for the address browsers reach (e.g. MinIO in Docker); defaults to the endpoint.
- `ContentDisposition::attachment()` / `inline()`: any file name, RFC 6266 `filename*=UTF-8''…` with
  an ASCII fallback.
- `StorageInterface`: `put()` (string or stream), `read()`, `readStream()`, `size()`, `deletePrefix()`,
  `downloadUrl()`; `FileNotFoundException` when reading a missing file.
- `MemoryStorage` (driver `memory`) for tests.
- `DirectUploads` (extends `StorageInterface`, implemented by `R2Storage`): `startUpload()`,
  `uploadPartUrl()`, `completeUpload()`, `abortUpload()`, `firstBytes()`. The bundle registers it per
  storage (`DirectUploads $privateStorage`); with a non-r2 driver, building it fails with a clear message.
- Integration tests (compose.yaml, CI) use VersityGW: MinIO no longer publishes Docker images.

**BC break** (allowed before 1.0): methods were added to `StorageInterface`, so a class of your own
implementing it must add them. Code that only calls the interface, the bundle configuration and the
existing `R2Client` / `R2Config` signatures are unchanged. With Composer, `^0.1` doesn't pick up 0.2:
require `^0.2`.

## 0.1.2

- Endpoint with an unparsable port no longer produces a wrong `Host` header.
- Retry pauses are always whole microseconds; retry loop simplified.
- Psalm level 1 added to `composer qa`; GitHub Actions run it, and the tests (with MinIO) on
  PHP 8.1 to 8.5, including the oldest dependencies allowed (Symfony 6.4, PHPUnit 10.5).

## 0.1.1

- MIT license.

## 0.1.0

- R2 client (S3 API, Signature V4 without the AWS SDK): upload (multipart above 64 MiB),
  upload a string, delete, exists, presigned GET and PUT links, retries.
- `StorageInterface` with `LocalStorage` and `R2Storage` (public or private), `StorageFactory`.
- Symfony bundle: named storages, driver from an env variable, autowiring by name.
- Based on choir's `MediaStorageService`.
