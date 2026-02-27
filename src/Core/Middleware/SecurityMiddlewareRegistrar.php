<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use Slim\App;

final class SecurityMiddlewareRegistrar
{
    public static function register(
        App $app,
        ApiPolicyMiddleware $apiPolicyMiddleware,
        AuthenticationMiddleware $authenticationMiddleware,
        AuthorizationMiddleware $authorizationMiddleware,
    ): void {
        // Slim 中 add() 采用 LIFO，因此反向注册以固定执行顺序为 Policy -> AuthN -> AuthZ
        $app->add($authorizationMiddleware);
        $app->add($authenticationMiddleware);
        $app->add($apiPolicyMiddleware);
    }
}
