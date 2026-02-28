<?php

declare(strict_types=1);

namespace App\Core\Plugin\Installer;

final readonly class ValidationResult
{
    private function __construct(
        public bool $valid,
        public ?string $error,
        public ?string $canonicalRepositoryUrl,
    ) {
    }

    public static function valid(string $canonicalRepositoryUrl): self
    {
        return new self(
            valid: true,
            error: null,
            canonicalRepositoryUrl: $canonicalRepositoryUrl,
        );
    }

    public static function invalid(string $error): self
    {
        return new self(
            valid: false,
            error: $error,
            canonicalRepositoryUrl: null,
        );
    }
}
