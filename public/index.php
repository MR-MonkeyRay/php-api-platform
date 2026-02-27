<?php

declare(strict_types=1);

use App\Core\Config\Config;
use App\Core\Controller\HealthController;
use App\Core\Error\ErrorHandler;
use App\Core\Logger\LoggerFactory;
use App\Core\Middleware\TraceContextMiddleware;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$config = new Config([
    'app' => [
        'name' => $_ENV['APP_NAME'] ?? 'php-api-platform',
        'env' => $_ENV['APP_ENV'] ?? 'development',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOL),
    ],
]);

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions([
    Config::class => $config,
    LoggerFactory::class => static fn (Config $config): LoggerFactory => new LoggerFactory(
        (string) $config->get('log.level', (string) ($_ENV['LOG_LEVEL'] ?? 'debug'))
    ),
    LoggerInterface::class => static fn (LoggerFactory $factory): LoggerInterface => $factory->create('app'),
]);
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->add($container->get(TraceContextMiddleware::class));

$app->get('/health', new HealthController());

$displayErrorDetails = (bool) $config->get('app.debug', false);
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
$errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

$app->run();
