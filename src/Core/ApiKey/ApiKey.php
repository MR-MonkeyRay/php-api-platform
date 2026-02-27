<?php

declare(strict_types=1);

namespace App\Core\ApiKey;

final readonly class ApiKey
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $kid,
        public array $scopes,
        public bool $active,
        public ?string $description,
        public ?string $expiresAt,
        public ?string $lastUsedAt,
        public ?string $revokedAt,
        public ?string $createdAt,
    ) {
    }
}
