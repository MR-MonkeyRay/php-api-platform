<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Config\Config;
use App\Core\Controller\HealthController;
use App\Core\Error\ErrorHandler;
use App\Core\Logger\LoggerFactory;
use App\Core\Middleware\TraceContextMiddleware;
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
        ]);

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions([
            Config::class => $config,
            LoggerFactory::class => static fn (Config $config): LoggerFactory => new LoggerFactory(
                (string) $config->get('log.level', 'debug')
            ),
            LoggerInterface::class => static fn (LoggerFactory $factory): LoggerInterface => $factory->create('app'),
        ]);
        $container = $containerBuilder->build();

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->add($container->get(TraceContextMiddleware::class));

        $app->get('/health', new HealthController());

        $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

        return $app;
    }
}
