<?php

declare(strict_types=1);

use App\Core\Plugin\ApiDefinition;
use App\Core\Plugin\PluginInterface;
use Slim\App;

final class AuditPlugin implements PluginInterface
{
    public function getId(): string
    {
        return 'audit';
    }

    public function getName(): string
    {
        return 'Audit Plugin';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function routes(App $app): void
    {
        $app->get('/plugin/audit/ping', static function ($request, $response) {
            $response->getBody()->write((string) json_encode([
                'plugin' => 'audit',
                'status' => 'ok',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('audit:ping:get');

        $app->post('/plugin/audit/clean', static function ($request, $response) {
            $pdo = $request->getAttribute('pdo');
            if (!$pdo instanceof \PDO) {
                $response->getBody()->write((string) json_encode([
                    'error' => 'pdo is required',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $deleted = 0;
            $statement = $pdo->prepare('DELETE FROM plugin_audit_event WHERE created_at < :cutoff');
            if ($statement !== false) {
                $cutoff = date('Y-m-d H:i:s', strtotime('-30 days') ?: time());
                $statement->execute(['cutoff' => $cutoff]);
                $deleted = (int) $statement->rowCount();
            }

            $response->getBody()->write((string) json_encode([
                'plugin' => 'audit',
                'command' => 'clean',
                'deleted' => $deleted,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('audit:clean:post');
    }

    public function apis(): array
    {
        return [
            new ApiDefinition('audit:ping:get', 'public', []),
            new ApiDefinition('audit:clean:post', 'private', ['admin']),
        ];
    }
}
