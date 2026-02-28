<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ApplicationFactory;

final class AdminSystemAccessTest extends TestCase
{
    private App $app;
    private string $policyDir;
    private string $setupVarDir;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->policyDir = sys_get_temp_dir() . '/admin-system-policy-' . $suffix;
        $this->setupVarDir = sys_get_temp_dir() . '/admin-system-setup-' . $suffix;

        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));
        file_put_contents($this->policyDir . '/snapshot.json', "{}\n");
        file_put_contents($this->policyDir . '/version', "1\n");

        $_ENV['API_KEY_PEPPER'] = 'pepper';
        $_ENV['ADMIN_USERNAME'] = 'admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('password', PASSWORD_BCRYPT);
        $_ENV['POLICY_DIR'] = $this->policyDir;
        $_ENV['SETUP_VAR_DIR'] = $this->setupVarDir;
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_PATH'] = ':memory:';

        putenv('API_KEY_PEPPER=pepper');
        putenv('ADMIN_USERNAME=admin');
        putenv('ADMIN_PASSWORD_HASH=' . $_ENV['ADMIN_PASSWORD_HASH']);
        putenv('POLICY_DIR=' . $this->policyDir);
        putenv('SETUP_VAR_DIR=' . $this->setupVarDir);
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_PATH=:memory:');

        $this->app = ApplicationFactory::create();
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['API_KEY_PEPPER'],
            $_ENV['ADMIN_USERNAME'],
            $_ENV['ADMIN_PASSWORD_HASH'],
            $_ENV['POLICY_DIR'],
            $_ENV['SETUP_VAR_DIR'],
            $_ENV['DB_CONNECTION'],
            $_ENV['DB_PATH'],
        );

        putenv('API_KEY_PEPPER');
        putenv('ADMIN_USERNAME');
        putenv('ADMIN_PASSWORD_HASH');
        putenv('POLICY_DIR');
        putenv('SETUP_VAR_DIR');
        putenv('DB_CONNECTION');
        putenv('DB_PATH');

        $this->deleteDirectory($this->policyDir);
        $this->deleteDirectory($this->setupVarDir);

        parent::tearDown();
    }

    public function testAdminSystemHealthDoesNotRequireAdminAuth(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/system/health');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('healthy', $payload['data']['status']);
    }

    public function testAdminSystemInfoRequiresAdminAuth(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/system/info');
        $response = $this->app->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Basic realm="Admin"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testAdminSystemInfoAcceptsValidAdminAuth(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/system/info')
            ->withHeader('Authorization', 'Basic ' . base64_encode('admin:password'));

        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('php_version', $payload['data']);
        self::assertArrayHasKey('database', $payload['data']);
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
