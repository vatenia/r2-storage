<?php

declare(strict_types=1);

namespace Vatenia\R2Storage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Vatenia\R2Storage\R2Client;
use Vatenia\R2Storage\Storage\DirectUploads;
use Vatenia\R2Storage\Storage\LocalStorage;
use Vatenia\R2Storage\Storage\MemoryStorage;
use Vatenia\R2Storage\Storage\R2Storage;
use Vatenia\R2Storage\Storage\StorageInterface;
use Vatenia\R2Storage\VateniaR2StorageBundle;

final class BundleTest extends TestCase
{
    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $env
     */
    private function container(array $config, array $env = []): ContainerBuilder
    {
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->set('http_client', new MockHttpClient());
        $extension = (new VateniaR2StorageBundle())->getContainerExtension();
        \assert(null !== $extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('vatenia_r2_storage', $config);
        $container->compile(true);

        return $container;
    }

    public function testNamedStoragesWithTheDriverFromAnEnvVariable(): void
    {
        $container = $this->container([
            'r2' => ['endpoint' => '%env(TEST_R2_ENDPOINT)%', 'access_key_id' => 'AKID', 'secret_access_key' => 'SECRET'],
            'storages' => [
                'public' => ['driver' => '%env(TEST_STORAGE_DRIVER)%', 'bucket' => 'site-media', 'public_url' => 'https://media.example.com'],
                'private' => ['driver' => 'r2', 'bucket' => 'site-private'],
            ],
        ], ['TEST_R2_ENDPOINT' => 'https://acc.r2.cloudflarestorage.com', 'TEST_STORAGE_DRIVER' => 'local']);

        self::assertInstanceOf(LocalStorage::class, $container->get('vatenia_r2_storage.storage.public'), 'driver from the env variable');
        self::assertInstanceOf(R2Storage::class, $container->get('vatenia_r2_storage.storage.private'));
        $private = $container->get('vatenia_r2_storage.storage.private');
        self::assertInstanceOf(R2Storage::class, $private);
        self::assertInstanceOf(R2Client::class, $private->client());
    }

    public function testAutowiringAliases(): void
    {
        // Checked before compiling: unused private aliases are removed then (apps use them first).
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test'); // both read by the extension on Symfony 6.4
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new VateniaR2StorageBundle())->getContainerExtension();
        \assert(null !== $extension);
        $extension->load([['storages' => ['public' => ['driver' => 'local'], 'private' => ['driver' => 'r2', 'bucket' => 'site-private']]]], $container);

        self::assertSame('vatenia_r2_storage.storage.public', (string) $container->getAlias(StorageInterface::class), 'the first storage is the default');
        self::assertTrue($container->hasAlias(StorageInterface::class.' $privateStorage'), 'injectable by name');
        self::assertTrue($container->hasAlias(DirectUploads::class.' $privateStorage'), 'direct uploads by name');
        self::assertTrue($container->hasAlias(R2Client::class));
    }

    public function testUnknownDefaultStorageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->container(['default_storage' => 'nope', 'storages' => ['public' => ['driver' => 'local']]]);
    }

    public function testPresignEndpointMemoryDriverAndDirectUploads(): void
    {
        $container = $this->container([
            'r2' => ['endpoint' => 'http://minio:9000', 'access_key_id' => 'AKID', 'secret_access_key' => 'SECRET', 'region' => 'us-east-1', 'presign_endpoint' => 'http://s3.app.local'],
            'storages' => [
                'private' => ['driver' => 'r2', 'bucket' => 'school-private'],
                'test' => ['driver' => 'memory'],
            ],
        ]);
        $private = $container->get('vatenia_r2_storage.storage.private');
        self::assertInstanceOf(R2Storage::class, $private);
        self::assertStringStartsWith('http://s3.app.local/school-private/a.pdf?', $private->url('a.pdf'), 'links for the browser-facing address');
        self::assertSame('http://minio:9000/school-private/a.pdf', $private->client()->objectUrl('school-private', 'a.pdf'));
        self::assertInstanceOf(MemoryStorage::class, $container->get('vatenia_r2_storage.storage.test'));
        self::assertSame($container->get('vatenia_r2_storage.storage.private'), $container->get('vatenia_r2_storage.storage.private.direct_uploads'));

        $this->expectException(\LogicException::class);
        $container->get('vatenia_r2_storage.storage.test.direct_uploads');
    }

    public function testExistingConfigWithoutPresignEndpoint(): void
    {
        $container = $this->container(['r2' => ['endpoint' => 'https://acc.r2.cloudflarestorage.com', 'access_key_id' => 'AKID', 'secret_access_key' => 'SECRET'], 'storages' => ['images' => ['driver' => 'local', 'bucket' => 'imgs', 'public_url' => 'https://img.example.com', 'directory' => sys_get_temp_dir(), 'local_url' => '/img']]]);
        $images = $container->get('vatenia_r2_storage.storage.images');
        self::assertInstanceOf(LocalStorage::class, $images);
        self::assertSame('/img/a.png', $images->url('a.png'));
    }
}
