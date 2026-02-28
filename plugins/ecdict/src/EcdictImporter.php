<?php

declare(strict_types=1);

namespace EcdictPlugin;

use PDO;

final class EcdictImporter
{
    /**
     * @param list<array<string, mixed>> $entries
     */
    public function import(PDO $pdo, array $entries): int
    {
        $inserted = 0;

        $statement = $pdo->prepare(
            'INSERT INTO plugin_ecdict_entry (word, definition, phonetic, updated_at) VALUES (:word, :definition, :phonetic, CURRENT_TIMESTAMP)'
        );

        if ($statement === false) {
            return 0;
        }

        foreach ($entries as $entry) {
            $word = trim((string) ($entry['word'] ?? ''));
            $definition = trim((string) ($entry['definition'] ?? ''));
            if ($word === '' || $definition === '') {
                continue;
            }

            $statement->execute([
                'word' => $word,
                'definition' => $definition,
                'phonetic' => trim((string) ($entry['phonetic'] ?? '')),
            ]);

            $inserted++;
        }

        return $inserted;
    }
}
