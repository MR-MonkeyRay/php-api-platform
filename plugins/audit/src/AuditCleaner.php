<?php

declare(strict_types=1);

namespace AuditPlugin;

use PDO;

final class AuditCleaner
{
    public function cleanOlderThan(PDO $pdo, int $days): int
    {
        $days = max(1, $days);
        $cutoff = date('Y-m-d H:i:s', strtotime(sprintf('-%d days', $days)) ?: time());

        $statement = $pdo->prepare('DELETE FROM plugin_audit_event WHERE created_at < :cutoff');
        if ($statement === false) {
            return 0;
        }

        $statement->execute(['cutoff' => $cutoff]);

        return (int) $statement->rowCount();
    }
}
