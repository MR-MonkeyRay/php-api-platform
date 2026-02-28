<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin\Audit;

use App\Core\Plugin\PluginManager;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AuditPluginTest extends TestCase
{
    private PDO $pdo;
    private \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 4) . '/plugins/audit/migrations/sqlite/001_plugin_audit.sql'));

        $manager = new PluginManager(new Logger('audit-plugin-test'));
        $manager->loadPlugins(dirname(__DIR__, 4) . '/plugins');

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->addRoutingMiddleware();
        $this->app->add(function ($request, $handler) {
            return $handler->handle($request->withAttribute('pdo', $this->pdo));
        });

        $manager->registerRoutes($this->app);
    }

    public function testAuditPingRouteIsAvailable(): void
    {
        $response = $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', '/plugin/audit/ping'));

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('audit', $payload['plugin']);
        self::assertSame('ok', $payload['status']);
    }

    public function testAuditCleanCommandDeletesOldRows(): void
    {
        $this->pdo->exec("INSERT INTO plugin_audit_event (level, message, context, created_at) VALUES ('info', 'old', '{}', datetime('now', '-40 day'))");
        $this->pdo->exec("INSERT INTO plugin_audit_event (level, message, context, created_at) VALUES ('info', 'new', '{}', datetime('now', '-1 day'))");

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/plugin/audit/clean');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('audit', $payload['plugin']);
        self::assertSame('clean', $payload['command']);
        self::assertSame(1, $payload['deleted']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM plugin_audit_event')->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testAuditPluginApiDefinitionsContainExpectedCommands(): void
    {
        $manager = new PluginManager(new Logger('audit-plugin-api-test'));
        $manager->loadPlugins(dirname(__DIR__, 4) . '/plugins');
        $apis = $manager->collectApis();

        $apiIds = array_map(static fn ($api): string => $api->apiId, $apis);

        self::assertContains('audit:ping:get', $apiIds);
        self::assertContains('audit:clean:post', $apiIds);
    }
}
