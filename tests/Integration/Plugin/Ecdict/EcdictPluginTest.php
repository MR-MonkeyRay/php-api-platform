<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin\Ecdict;

use App\Core\Plugin\PluginManager;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class EcdictPluginTest extends TestCase
{
    private PDO $pdo;
    private \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 4) . '/plugins/ecdict/migrations/sqlite/001_plugin_ecdict.sql'));

        $manager = new PluginManager(new Logger('ecdict-plugin-test'));
        $manager->loadPlugins(dirname(__DIR__, 4) . '/plugins');

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->addRoutingMiddleware();
        $this->app->add(function ($request, $handler) {
            return $handler->handle($request->withAttribute('pdo', $this->pdo));
        });

        $manager->registerRoutes($this->app);
    }

    public function testEcdictPingRouteIsAvailable(): void
    {
        $response = $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', '/plugin/ecdict/ping'));

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ecdict', $payload['plugin']);
        self::assertSame('ok', $payload['status']);
    }

    public function testEcdictImportCommandInsertsEntries(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/plugin/ecdict/import');
        $request->getBody()->write((string) json_encode([
            'entries' => [
                ['word' => 'hello', 'definition' => '你好', 'phonetic' => 'həˈləʊ'],
                ['word' => 'world', 'definition' => '世界'],
                ['word' => '', 'definition' => 'skip'],
            ],
        ], JSON_THROW_ON_ERROR));
        $request = $request->withHeader('Content-Type', 'application/json');

        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ecdict', $payload['plugin']);
        self::assertSame('import', $payload['command']);
        self::assertSame(2, $payload['imported']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM plugin_ecdict_entry')->fetchColumn();
        self::assertSame(2, $count);
    }

    public function testEcdictPluginApiDefinitionsContainExpectedCommands(): void
    {
        $manager = new PluginManager(new Logger('ecdict-plugin-api-test'));
        $manager->loadPlugins(dirname(__DIR__, 4) . '/plugins');
        $apis = $manager->collectApis();

        $apiIds = array_map(static fn ($api): string => $api->apiId, $apis);

        self::assertContains('ecdict:ping:get', $apiIds);
        self::assertContains('ecdict:import:post', $apiIds);
    }
}
