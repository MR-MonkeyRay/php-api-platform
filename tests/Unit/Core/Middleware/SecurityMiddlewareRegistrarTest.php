<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Middleware\ApiPolicyMiddleware;
use App\Core\Middleware\AuthenticationMiddleware;
use App\Core\Middleware\AuthorizationMiddleware;
use App\Core\Middleware\SecurityMiddlewareRegistrar;
use App\Core\Policy\PolicyProvider;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\App;

final class SecurityMiddlewareRegistrarTest extends TestCase
{
    private string $policyDir;
    private string $versionFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policyDir = sys_get_temp_dir() . '/security-registrar-policy-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));
        file_put_contents($this->policyDir . '/snapshot.json', "{}\n");
        file_put_contents($this->policyDir . '/version', "1\n");

        $this->versionFile = sys_get_temp_dir() . '/security-registrar-apikey-' . bin2hex(random_bytes(6));
        file_put_contents($this->versionFile, "1\n");
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink($this->policyDir . '/snapshot.json');
        @unlink($this->policyDir . '/version');
        @rmdir($this->policyDir);
        @unlink($this->versionFile);
    }

    public function testRegisterAddsMiddlewaresInFixedOrder(): void
    {
        $app = $this->getMockBuilder(App::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['add'])
            ->getMock();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE api_key (
                kid TEXT PRIMARY KEY,
                secret_hash TEXT NOT NULL,
                scopes TEXT NOT NULL DEFAULT '[]',
                active INTEGER NOT NULL DEFAULT 1,
                description TEXT,
                expires_at TEXT,
                last_used_at TEXT,
                revoked_at TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL
        );

        $apiPolicyMiddleware = new ApiPolicyMiddleware(new PolicyProvider($this->policyDir));
        $authenticationMiddleware = new AuthenticationMiddleware(new ApiKeyProvider($pdo, 'test-pepper', $this->versionFile));
        $authorizationMiddleware = new AuthorizationMiddleware();

        $order = [];
        $map = [
            spl_object_id($authorizationMiddleware) => 'authz',
            spl_object_id($authenticationMiddleware) => 'authn',
            spl_object_id($apiPolicyMiddleware) => 'policy',
        ];

        $app
            ->expects(self::exactly(3))
            ->method('add')
            ->willReturnCallback(static function (object $middleware) use (&$order, $map, $app): App {
                $order[] = $map[spl_object_id($middleware)] ?? 'unknown';

                return $app;
            });

        SecurityMiddlewareRegistrar::register(
            $app,
            $apiPolicyMiddleware,
            $authenticationMiddleware,
            $authorizationMiddleware,
        );

        self::assertSame(['authz', 'authn', 'policy'], $order);
    }
}
