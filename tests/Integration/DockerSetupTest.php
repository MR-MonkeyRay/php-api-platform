<?php

declare(strict_types=1);

namespace Tests\Integration;

final class DockerSetupTest extends AppTestCase
{
    public function testDockerComposeFileExists(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/docker-compose.yml');
    }

    public function testDockerfileUsesPhp84FpmAlpineWithApcu(): void
    {
        $dockerfile = dirname(__DIR__, 2) . '/docker/app/Dockerfile';

        self::assertFileExists($dockerfile);

        $content = file_get_contents($dockerfile);
        self::assertNotFalse($content);

        self::assertStringContainsString('FROM php:8.4-fpm-alpine', $content);
        self::assertStringContainsString('pecl install apcu', $content);
        self::assertStringContainsString('docker-php-ext-enable apcu', $content);
    }

    public function testNginxConfigContainsSecurityHeadersAndPhpFpmPass(): void
    {
        $nginxConfig = dirname(__DIR__, 2) . '/docker/nginx/default.conf';

        self::assertFileExists($nginxConfig);

        $content = file_get_contents($nginxConfig);
        self::assertNotFalse($content);

        self::assertStringContainsString('add_header X-Content-Type-Options "nosniff" always;', $content);
        self::assertStringContainsString('add_header X-Frame-Options "DENY" always;', $content);
        self::assertStringContainsString('fastcgi_pass app:9000;', $content);
    }
}
