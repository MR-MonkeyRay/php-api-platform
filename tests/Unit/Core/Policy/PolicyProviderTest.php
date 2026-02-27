<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Policy;

use App\Core\Policy\PolicyProvider;
use PHPUnit\Framework\TestCase;

final class PolicyProviderTest extends TestCase
{
    private string $policyDir;
    private int $versionTimestamp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policyDir = sys_get_temp_dir() . '/policy_provider_' . bin2hex(random_bytes(6));
        mkdir($this->policyDir, 0777, true);
        $this->versionTimestamp = time();

        $this->writeSnapshot([
            'test:api:get' => ['enabled' => true, 'visibility' => 'public'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->policyDir);

        parent::tearDown();
    }

    public function testGetPolicyReturnsPolicy(): void
    {
        $provider = new PolicyProvider($this->policyDir);

        $policy = $provider->getPolicy('test:api:get');

        self::assertNotNull($policy);
        self::assertTrue((bool) ($policy['enabled'] ?? false));
    }

    public function testGetPolicyReturnsNullForUnknownApi(): void
    {
        $provider = new PolicyProvider($this->policyDir);

        self::assertNull($provider->getPolicy('unknown:api'));
    }

    public function testReloadsWhenVersionChanges(): void
    {
        $provider = new PolicyProvider($this->policyDir);

        $initial = $provider->getPolicy('test:api:get');
        self::assertNotNull($initial);
        self::assertTrue((bool) $initial['enabled']);

        usleep(600_000);
        $this->writeSnapshot([
            'test:api:get' => ['enabled' => false, 'visibility' => 'private'],
        ]);

        $reloaded = $provider->getPolicy('test:api:get');

        self::assertNotNull($reloaded);
        self::assertFalse((bool) $reloaded['enabled']);
        self::assertSame('private', $reloaded['visibility']);
    }

    public function testDebouncePreventsImmediateReload(): void
    {
        $provider = new PolicyProvider($this->policyDir, debounceMilliseconds: 500.0);

        $first = $provider->getPolicy('test:api:get');
        self::assertNotNull($first);
        self::assertTrue((bool) $first['enabled']);

        $this->writeSnapshot([
            'test:api:get' => ['enabled' => false],
        ]);

        $stillOld = $provider->getPolicy('test:api:get');
        self::assertNotNull($stillOld);
        self::assertTrue((bool) $stillOld['enabled']);

        usleep(600_000);
        $newValue = $provider->getPolicy('test:api:get');
        self::assertNotNull($newValue);
        self::assertFalse((bool) $newValue['enabled']);
    }

    public function testFallsBackToLastGoodSnapshotOnCorruptFile(): void
    {
        $provider = new PolicyProvider($this->policyDir);

        $loaded = $provider->getPolicy('test:api:get');
        self::assertNotNull($loaded);
        self::assertTrue((bool) $loaded['enabled']);

        file_put_contents($this->policyDir . '/snapshot.json', '{invalid json');
        usleep(1_100_000);
        touch($this->policyDir . '/version', $this->nextVersionTimestamp());

        usleep(600_000);
        $fallback = $provider->getPolicy('test:api:get');

        self::assertNotNull($fallback);
        self::assertTrue((bool) $fallback['enabled']);
    }

    /**
     * @param array<string, array<string, mixed>> $snapshot
     */
    private function writeSnapshot(array $snapshot): void
    {
        file_put_contents(
            $this->policyDir . '/snapshot.json',
            (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        file_put_contents($this->policyDir . '/version', sprintf('%.6f', microtime(true)));
        touch($this->policyDir . '/version', $this->nextVersionTimestamp());
    }

    private function nextVersionTimestamp(): int
    {
        $this->versionTimestamp++;

        return $this->versionTimestamp;
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
