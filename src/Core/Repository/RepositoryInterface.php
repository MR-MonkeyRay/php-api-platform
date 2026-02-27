<?php

declare(strict_types=1);

namespace App\Core\Repository;

interface RepositoryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array;
}
