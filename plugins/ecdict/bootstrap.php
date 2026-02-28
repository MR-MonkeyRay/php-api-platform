<?php

declare(strict_types=1);

use App\Core\Plugin\ApiDefinition;
use App\Core\Plugin\PluginInterface;
use Slim\App;

final class EcdictPlugin implements PluginInterface
{
    public function getId(): string
    {
        return 'ecdict';
    }

    public function getName(): string
    {
        return 'ECDICT Plugin';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function routes(App $app): void
    {
        $app->get('/plugin/ecdict/ping', static function ($request, $response) {
            $response->getBody()->write((string) json_encode([
                'plugin' => 'ecdict',
                'status' => 'ok',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('ecdict:ping:get');

        $app->post('/plugin/ecdict/import', static function ($request, $response) {
            $pdo = $request->getAttribute('pdo');
            if (!$pdo instanceof \PDO) {
                $response->getBody()->write((string) json_encode([
                    'error' => 'pdo is required',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $body = trim((string) $request->getBody());
            $payload = $body === '' ? [] : json_decode($body, true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $entries = $payload['entries'] ?? [];
            if (!is_array($entries)) {
                $entries = [];
            }

            $imported = 0;
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $word = trim((string) ($entry['word'] ?? ''));
                $definition = trim((string) ($entry['definition'] ?? ''));
                if ($word === '' || $definition === '') {
                    continue;
                }

                $statement = $pdo->prepare(
                    'INSERT INTO plugin_ecdict_entry (word, definition, phonetic, updated_at) VALUES (:word, :definition, :phonetic, CURRENT_TIMESTAMP)'
                );
                if ($statement === false) {
                    continue;
                }

                $statement->execute([
                    'word' => $word,
                    'definition' => $definition,
                    'phonetic' => trim((string) ($entry['phonetic'] ?? '')),
                ]);
                $imported++;
            }

            $response->getBody()->write((string) json_encode([
                'plugin' => 'ecdict',
                'command' => 'import',
                'imported' => $imported,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('ecdict:import:post');
    }

    public function apis(): array
    {
        return [
            new ApiDefinition('ecdict:ping:get', 'public', []),
            new ApiDefinition('ecdict:import:post', 'private', ['admin']),
        ];
    }
}
