<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\ApiKey\ApiKeyGenerator;
use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Config\Config;
use App\Core\Controller\ApiKeyController;
use App\Core\Controller\HealthController;
use App\Core\Database\ConnectionFactory;
use App\Core\Error\ErrorHandler;
use App\Core\Logger\LoggerFactory;
use App\Core\Middleware\ApiPolicyMiddleware;
use App\Core\Middleware\AuthenticationMiddleware;
use App\Core\Middleware\AuthorizationMiddleware;
use App\Core\Middleware\SecurityMiddlewareRegistrar;
use App\Core\Middleware\TraceContextMiddleware;
use App\Core\Policy\PolicyProvider;
use App\Core\Repository\ApiKeyRepository;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

final class ApplicationFactory
{
    public static function create(bool $displayErrorDetails = false): App
    {
        $config = new Config([
            'app' => [
                'name' => $_ENV['APP_NAME'] ?? 'php-api-platform',
                'env' => $_ENV['APP_ENV'] ?? 'test',
                'debug' => $displayErrorDetails,
            ],
            'log' => [
                'level' => $_ENV['LOG_LEVEL'] ?? 'debug',
            ],
            'database' => [
                'type' => $_ENV['DB_CONNECTION'] ?? 'sqlite',
                'path' => $_ENV['DB_PATH'] ?? ':memory:',
                'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
                'name' => $_ENV['DB_NAME'] ?? 'app',
                'user' => $_ENV['DB_USER'] ?? 'app',
                'password' => $_ENV['DB_PASSWORD'] ?? 'app',
                'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            ],
            'policy' => [
                'dir' => $_ENV['POLICY_DIR'] ?? 'var/policy',
            ],
            'api_key' => [
                'version_file' => $_ENV['API_KEY_VERSION_FILE'] ?? 'var/apikey.version',
                'cache_ttl' => (int) ($_ENV['API_KEY_CACHE_TTL'] ?? 30),
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

        $pepper = trim((string) ($_ENV['API_KEY_PEPPER'] ?? getenv('API_KEY_PEPPER') ?: ''));
        if ($pepper !== '') {
            $pdo = $container->get(ConnectionFactory::class)->create();
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

            SecurityMiddlewareRegistrar::register(
                $app,
                $apiPolicyMiddleware,
                $authenticationMiddleware,
                $authorizationMiddleware,
            );

            $apiKeyController = new ApiKeyController(
                new ApiKeyRepository($pdo),
                $apiKeyProvider,
                new ApiKeyGenerator(),
                $pdo,
                $pepper,
            );

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
