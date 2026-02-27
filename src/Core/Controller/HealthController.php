<?php

declare(strict_types=1);

namespace App\Core\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HealthController
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = [
            'status' => 'ok',
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];

        $response->getBody()->write(
            (string) json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            )
        );

        return $response->withHeader('Content-Type', 'application/json');
    }
}

