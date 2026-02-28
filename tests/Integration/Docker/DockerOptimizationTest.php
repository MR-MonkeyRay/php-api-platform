<?php

declare(strict_types=1);

namespace Tests\Integration\Docker;

use PHPUnit\Framework\TestCase;

final class DockerOptimizationTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = dirname(__DIR__, 3);
    }

    public function testHealthCheckConfiguredForCoreServices(): void
    {
        $compose = (string) file_get_contents($this->projectDir . '/docker-compose.yml');

        self::assertStringContainsString('app:', $compose);
        self::assertStringContainsString('mysql:', $compose);
        self::assertStringContainsString('pgsql:', $compose);
        self::assertStringContainsString('nginx:', $compose);
        self::assertStringContainsString('healthcheck:', $compose);
        self::assertStringContainsString('php -v >/dev/null 2>&1 || exit 1', $compose);
        self::assertStringContainsString('mysqladmin ping', $compose);
        self::assertStringContainsString('pg_isready', $compose);
        self::assertStringContainsString('http://127.0.0.1/health', $compose);
    }

    public function testResourceLimitsConfigured(): void
    {
        $compose = (string) file_get_contents($this->projectDir . '/docker-compose.yml');

        self::assertStringContainsString('pids_limit:', $compose);
        self::assertStringContainsString('mem_limit:', $compose);
        self::assertStringContainsString('cpus:', $compose);
        self::assertStringContainsString('deploy:', $compose);
        self::assertStringContainsString('resources:', $compose);
        self::assertStringContainsString('limits:', $compose);
    }

    public function testDockerfileUsesNonRootUser(): void
    {
        $dockerfile = (string) file_get_contents($this->projectDir . '/docker/app/Dockerfile');

        self::assertStringContainsString('adduser', $dockerfile);
        self::assertStringContainsString('USER ${APP_USER}', $dockerfile);
        self::assertStringNotContainsString('USER root', $dockerfile);
    }

    public function testDockerfileUsesMultiStageBuild(): void
    {
        $dockerfile = (string) file_get_contents($this->projectDir . '/docker/app/Dockerfile');

        self::assertStringContainsString('FROM composer:2 AS composer-bin', $dockerfile);
        self::assertStringContainsString('FROM php:8.4-fpm-alpine AS app-runtime', $dockerfile);
        self::assertStringContainsString('COPY --from=composer-bin', $dockerfile);
    }

    public function testDockerignoreExistsAndCoversCommonBuildNoise(): void
    {
        $file = $this->projectDir . '/.dockerignore';
        self::assertFileExists($file);

        $content = (string) file_get_contents($file);

        self::assertStringContainsString('vendor', $content);
        self::assertStringContainsString('.git', $content);
        self::assertStringContainsString('.omx', $content);
    }
}
