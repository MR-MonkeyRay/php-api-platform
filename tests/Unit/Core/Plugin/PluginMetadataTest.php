<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plugin;

use App\Core\Plugin\InvalidPluginException;
use App\Core\Plugin\PluginMetadata;
use PHPUnit\Framework\TestCase;

final class PluginMetadataTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempFile = tempnam(sys_get_temp_dir(), 'plugin-json-');
        self::assertNotFalse($this->tempFile);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testParseValidPluginJson(): void
    {
        $json = json_encode([
            'id' => 'test-plugin',
            'name' => 'Test Plugin',
            'version' => '1.0.0',
            'mainClass' => 'TestPlugin',
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->tempFile, $json);

        $metadata = PluginMetadata::fromFile($this->tempFile);

        self::assertSame('test-plugin', $metadata->id);
        self::assertSame('Test Plugin', $metadata->name);
        self::assertSame('1.0.0', $metadata->version);
        self::assertSame('TestPlugin', $metadata->mainClass);
    }

    public function testRejectsInvalidId(): void
    {
        $json = json_encode([
            'id' => 'Invalid_ID!',
            'name' => 'Test',
            'version' => '1.0.0',
            'mainClass' => 'TestPlugin',
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->tempFile, $json);

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('id');

        PluginMetadata::fromFile($this->tempFile);
    }

    public function testRejectsInvalidVersion(): void
    {
        $json = json_encode([
            'id' => 'test-plugin',
            'name' => 'Test',
            'version' => 'not-semver',
            'mainClass' => 'TestPlugin',
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->tempFile, $json);

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('version');

        PluginMetadata::fromFile($this->tempFile);
    }

    public function testRequiresAllFields(): void
    {
        $json = json_encode([
            'id' => 'test-plugin',
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->tempFile, $json);

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('Missing required field');

        PluginMetadata::fromFile($this->tempFile);
    }
}
