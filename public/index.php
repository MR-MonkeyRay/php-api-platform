<?php

declare(strict_types=1);

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
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$readEnv = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);
    if (!is_string($value) || $value === '') {
        return $default;
    }

    return $value;
};

$dbConnection = strtolower(trim($readEnv('DB_CONNECTION', $readEnv('DB_TYPE', 'sqlite'))));
$dbPath = $readEnv('DB_PATH', 'var/database/app.sqlite');
$dbHost = $readEnv('DB_HOST', '127.0.0.1');
$dbPort = (int) $readEnv('DB_PORT', '3306');
$dbName = $readEnv('DB_NAME', 'app');
$dbUser = $readEnv('DB_USER', 'app');
$dbPassword = $readEnv('DB_PASSWORD', 'app');
$dbCharset = $readEnv('DB_CHARSET', 'utf8mb4');

$config = new Config([
    'app' => [
        'name' => $readEnv('APP_NAME', 'php-api-platform'),
        'env' => $readEnv('APP_ENV', 'development'),
        'debug' => filter_var($readEnv('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    ],
    'log' => [
        'level' => $readEnv('LOG_LEVEL', 'debug'),
    ],
    'database' => [
        'type' => $dbConnection,
        'path' => $dbPath,
        'host' => $dbHost,
        'port' => $dbPort,
        'name' => $dbName,
        'user' => $dbUser,
        'password' => $dbPassword,
        'charset' => $dbCharset,
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

$connectionFactory = $container->get(ConnectionFactory::class);
$setupDetector = new SetupDetector($readEnv('SETUP_VAR_DIR', 'var'));

$hasEnvFirstSetupConfig = static function () use ($dbConnection, $dbPath, $dbHost, $dbName, $dbUser, $readEnv): bool {
    $adminUsername = trim($readEnv('ADMIN_USERNAME'));
    $adminPasswordHash = trim($readEnv('ADMIN_PASSWORD_HASH'));
    if ($adminUsername === '' || $adminPasswordHash === '') {
        return false;
    }

    if ($dbConnection === 'sqlite') {
        return trim($dbPath) !== '';
    }

    if ($dbConnection === 'mysql' || $dbConnection === 'pgsql') {
        return trim($dbHost) !== '' && trim($dbName) !== '' && trim($dbUser) !== '';
    }

    return false;
};

if (!$setupDetector->isInstalled() && $hasEnvFirstSetupConfig()) {
    try {
        $setupPdo = $connectionFactory->create();
        $runner = new MigrationRunner(
            $setupPdo,
            (string) $config->get('database.type', 'sqlite'),
            dirname(__DIR__) . '/migrations',
        );
        $runner->run();
        $setupDetector->markInstalled();
    } catch (Throwable $exception) {
        throw new RuntimeException('Env-first setup failed: ' . $exception->getMessage(), 0, $exception);
    }
}

$pepper = trim($readEnv('API_KEY_PEPPER'));
if ($pepper === '') {
    throw new RuntimeException('API_KEY_PEPPER is required.');
}

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
$apiKeyController = new ApiKeyController(
    new ApiKeyRepository($pdo),
    $apiKeyProvider,
    new ApiKeyGenerator(),
    $pdo,
    $pepper,
);

AppFactory::setContainer($container);
$app = AppFactory::create();

SecurityMiddlewareRegistrar::register(
    $app,
    $apiPolicyMiddleware,
    $authenticationMiddleware,
    $authorizationMiddleware,
);
$app->add($adminAuthMiddleware);
$app->addRoutingMiddleware();
$app->add($container->get(TraceContextMiddleware::class));

$app->get('/health', new HealthController());
$app->get('/admin/system/health', [$systemController, 'health'])->setName('admin:system:health');
$app->get('/admin/system/info', [$systemController, 'info'])->setName('admin:system:info');
$app->post('/admin/api-keys', [$apiKeyController, 'create'])->setName('admin:api-keys:create');
$app->get('/admin/api-keys', [$apiKeyController, 'list'])->setName('admin:api-keys:list');
$app->get('/admin/api-keys/{kid}', [$apiKeyController, 'get'])->setName('admin:api-keys:get');
$app->delete('/admin/api-keys/{kid}', [$apiKeyController, 'revoke'])->setName('admin:api-keys:revoke');

$displayErrorDetails = (bool) $config->get('app.debug', false);
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
$errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

$app->run();
