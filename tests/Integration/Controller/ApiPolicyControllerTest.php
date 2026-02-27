<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Core\Config\Config;
use App\Core\Controller\ApiPolicyController;
use App\Core\Database\ConnectionFactory;
use App\Core\Database\Migration\MigrationRunner;
use App\Core\Error\ErrorHandler;
use App\Core\Policy\SnapshotBuilder;
use App\Core\Repository\ApiPolicyRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiPolicyControllerTest extends TestCase
{
    private PDO $pdo;
    private string $policyDir;
    private \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = new ConnectionFactory(new Config([
            'database' => [
                'type' => 'sqlite',
                'path' => ':memory:',
            ],
        ]));
        $this->pdo = $factory->create();

        $runner = new MigrationRunner($this->pdo, 'sqlite', dirname(__DIR__, 3) . '/migrations');
        $runner->run();

        $this->policyDir = sys_get_temp_dir() . '/php-api-platform-controller-policy-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));

        $repository = new ApiPolicyRepository($this->pdo);
        $snapshotBuilder = new SnapshotBuilder($this->pdo, $this->policyDir);
        $controller = new ApiPolicyController($repository, $snapshotBuilder, $this->pdo);

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->addRoutingMiddleware();
        $this->app->get('/admin/api-policy', [$controller, 'list']);
        $this->app->get('/admin/api-policy/{apiId}', [$controller, 'get']);
        $this->app->post('/admin/api-policy', [$controller, 'upsert']);

        $errorMiddleware = $this->app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $files = glob($this->policyDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        if (is_dir($this->policyDir)) {
            rmdir($this->policyDir);
        }
    }

    public function testListPolicies(): void
    {
        $this->createPolicy(['api_id' => 'test:api:1', 'plugin_id' => 'test']);
        $this->createPolicy(['api_id' => 'test:api:2', 'plugin_id' => 'test']);

        $response = $this->request('GET', '/admin/api-policy');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
    }

    public function testGetPolicy(): void
    {
        $this->createPolicy([
            'api_id' => 'test:api:get',
            'plugin_id' => 'test',
            'visibility' => 'public',
        ]);

        $response = $this->request('GET', '/admin/api-policy/test:api:get');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('public', $body['data']['visibility']);
    }

    public function testGetPolicyNotFound(): void
    {
        $response = $this->request('GET', '/admin/api-policy/nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testUpsertCreatesNew(): void
    {
        $response = $this->request('POST', '/admin/api-policy', [
            'api_id' => 'new:api:get',
            'plugin_id' => 'new',
            'visibility' => 'public',
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('new:api:get', $body['data']['api_id']);
    }

    public function testUpsertUpdatesExisting(): void
    {
        $this->createPolicy([
            'api_id' => 'test:api',
            'plugin_id' => 'test',
            'enabled' => 1,
        ]);

        $response = $this->request('POST', '/admin/api-policy', [
            'api_id' => 'test:api',
            'enabled' => 0,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $policy = $this->getPolicy('test:api');
        self::assertSame(0, $policy['enabled']);
    }

    public function testUpsertRebuildsSnapshot(): void
    {
        $response = $this->request('POST', '/admin/api-policy', [
            'api_id' => 'test:api',
            'plugin_id' => 'test',
        ]);

        self::assertSame(201, $response->getStatusCode());

        $snapshot = json_decode(
            (string) file_get_contents($this->policyDir . '/snapshot.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertArrayHasKey('test:api', $snapshot);
    }

    public function testAuditLogCreated(): void
    {
        $response = $this->request('POST', '/admin/api-policy', [
            'api_id' => 'test:api',
            'plugin_id' => 'test',
        ], [
            'X-Admin-User' => 'admin-user',
        ]);

        self::assertSame(201, $response->getStatusCode());

        $logs = $this->getAuditLogs();
        self::assertNotEmpty($logs);
        self::assertStringContainsString('api_policy', $logs[0]);
        self::assertStringContainsString('admin-user', $logs[0]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function request(string $method, string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($data !== []) {
            $request->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function createPolicy(array $policy): void
    {
        $repository = new ApiPolicyRepository($this->pdo);
        $repository->upsert($policy);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPolicy(string $apiId): array
    {
        $repository = new ApiPolicyRepository($this->pdo);
        $policy = $repository->findByApiId($apiId);
        self::assertNotNull($policy);

        return $policy;
    }

    /**
     * @return list<string>
     */
    private function getAuditLogs(): array
    {
        $statement = $this->pdo->query(
            'SELECT actor || ":" || action || ":" || target_type || ":" || target_id FROM audit_log ORDER BY id DESC'
        );
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(static fn (mixed $row): string => (string) $row, $rows));
    }
}
