<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\ApiKey\ApiKeyGenerator;
use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Config\Config;
use App\Core\Controller\ApiKeyController;
use App\Core\Controller\HealthController;
use App\Core\Controller\SystemController;
use App\Core\Database\ConnectionFactory;
use App\Core\Database\Migration\MigrationRunner;
use App\Core\Error\ErrorHandler;
use App\Core\Logger\LoggerFactory;
use App\Core\Middleware\AdminAuthMiddleware;
use App\Core\Middleware\ApiPolicyMiddleware;
use App\Core\Middleware\AuthenticationMiddleware;
use App\Core\Middleware\AuthorizationMiddleware;
use App\Core\Middleware\SecurityMiddlewareRegistrar;
use App\Core\Middleware\TraceContextMiddleware;
use App\Core\Policy\PolicyProvider;
use App\Core\Repository\ApiKeyRepository;
use App\Core\Setup\SetupDetector;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

final class ApplicationFactory
{
    public static function create(bool $displayErrorDetails = false): App
    {
        $readEnv = static function (string $key, string $default = ''): string {
            $value = $_ENV[$key] ?? getenv($key);
            if (!is_string($value) || $value === '') {
                return $default;
            }

            return $value;
        };

        $dbConnection = strtolower(trim($readEnv('DB_CONNECTION', $readEnv('DB_TYPE', 'sqlite'))));

        $config = new Config([
            'app' => [
                'name' => $readEnv('APP_NAME', 'php-api-platform'),
                'env' => $readEnv('APP_ENV', 'test'),
                'debug' => $displayErrorDetails,
            ],
            'log' => [
                'level' => $readEnv('LOG_LEVEL', 'debug'),
            ],
            'database' => [
                'type' => $dbConnection,
                'path' => $readEnv('DB_PATH', ':memory:'),
                'host' => $readEnv('DB_HOST', '127.0.0.1'),
                'port' => (int) $readEnv('DB_PORT', '3306'),
                'name' => $readEnv('DB_NAME', 'app'),
                'user' => $readEnv('DB_USER', 'app'),
                'password' => $readEnv('DB_PASSWORD', 'app'),
                'charset' => $readEnv('DB_CHARSET', 'utf8mb4'),
            ],
            'policy' => [
                'dir' => $readEnv('POLICY_DIR', 'var/policy'),
            ],
            'api_key' => [
                'version_file' => $readEnv('API_KEY_VERSION_FILE', 'var/apikey.version'),
                'cache_ttl' => (int) $readEnv('API_KEY_CACHE_TTL', '30'),
            ],
        ]);

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions([
            Config::class => $config,
            LoggerFactory::class => static fn (Config $config): LoggerFactory => new LoggerFactory(
                (string) $config->get('log.level', 'debug')
            ),
            LoggerInterface::class => static fn (LoggerFactory $factory): LoggerInterface => $factory->create('app'),
            TraceContextMiddleware::class => static fn (LoggerInterface $logger): TraceContextMiddleware => new TraceContextMiddleware($logger),
            ConnectionFactory::class => static fn (Config $config): ConnectionFactory => new ConnectionFactory($config),
        ]);
        $container = $containerBuilder->build();

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $connectionFactory = $container->get(ConnectionFactory::class);
        $setupDetector = new SetupDetector($readEnv('SETUP_VAR_DIR', 'var'));

        $hasEnvFirstSetupConfig = static function () use ($dbConnection, $readEnv): bool {
            $adminUsername = trim($readEnv('ADMIN_USERNAME'));
            $adminPasswordHash = trim($readEnv('ADMIN_PASSWORD_HASH'));
            if ($adminUsername === '' || $adminPasswordHash === '') {
                return false;
            }

            if ($dbConnection === 'sqlite') {
                return trim($readEnv('DB_PATH', ':memory:')) !== '';
            }

            if ($dbConnection === 'mysql' || $dbConnection === 'pgsql') {
                return trim($readEnv('DB_HOST')) !== ''
                    && trim($readEnv('DB_NAME')) !== ''
                    && trim($readEnv('DB_USER')) !== '';
            }

            return false;
        };

        if (!$setupDetector->isInstalled() && $hasEnvFirstSetupConfig()) {
            try {
                $setupPdo = $connectionFactory->create();
                $runner = new MigrationRunner(
                    $setupPdo,
                    (string) $config->get('database.type', 'sqlite'),
                    dirname(__DIR__, 2) . '/migrations',
                );
                $runner->run();
                $setupDetector->markInstalled();
            } catch (\Throwable) {
                // 测试工厂中 setup 失败不阻断，用于兼容仅单元级场景。
            }
        }

        $pepper = trim((string) ($readEnv('API_KEY_PEPPER')));
        if ($pepper !== '') {
            $pdo = $connectionFactory->create();
            $policyProvider = new PolicyProvider((string) $config->get('policy.dir', 'var/policy'));
            $apiKeyProvider = new ApiKeyProvider(
                $pdo,
                $pepper,
                (string) $config->get('api_key.version_file', 'var/apikey.version'),
                (int) $config->get('api_key.cache_ttl', 30),
            );

            $apiPolicyMiddleware = new ApiPolicyMiddleware($policyProvider);
            $authenticationMiddleware = new AuthenticationMiddleware($apiKeyProvider);
            $authorizationMiddleware = new AuthorizationMiddleware();
            $adminAuthMiddleware = new AdminAuthMiddleware();
            $systemController = new SystemController(
                $pdo,
                (string) $config->get('policy.dir', 'var/policy'),
                $readEnv('PLUGINS_DIR', 'plugins'),
            );

            SecurityMiddlewareRegistrar::register(
                $app,
                $apiPolicyMiddleware,
                $authenticationMiddleware,
                $authorizationMiddleware,
            );

            $app->add($adminAuthMiddleware);

            $apiKeyController = new ApiKeyController(
                new ApiKeyRepository($pdo),
                $apiKeyProvider,
                new ApiKeyGenerator(),
                $pdo,
                $pepper,
            );

            $app->get('/admin/system/health', [$systemController, 'health'])->setName('admin:system:health');
            $app->get('/admin/system/info', [$systemController, 'info'])->setName('admin:system:info');
            $app->post('/admin/api-keys', [$apiKeyController, 'create'])->setName('admin:api-keys:create');
            $app->get('/admin/api-keys', [$apiKeyController, 'list'])->setName('admin:api-keys:list');
            $app->get('/admin/api-keys/{kid}', [$apiKeyController, 'get'])->setName('admin:api-keys:get');
            $app->delete('/admin/api-keys/{kid}', [$apiKeyController, 'revoke'])->setName('admin:api-keys:revoke');
        }

        $app->addRoutingMiddleware();
        $app->add($container->get(TraceContextMiddleware::class));

        $app->get('/health', new HealthController());

        $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

        return $app;
    }
}
