<?php

declare(strict_types=1);

namespace App\Core\Plugin\Installer;

final readonly class DownloadResult
{
    private function __construct(
        public bool $success,
        public ?string $error,
        public string $destination,
        public string $output,
    ) {
    }

    public static function success(string $destination, string $output = ''): self
    {
        return new self(
            success: true,
            error: null,
            destination: $destination,
            output: $output,
        );
    }

    public static function failure(string $destination, string $error, string $output = ''): self
    {
        return new self(
            success: false,
            error: $error,
            destination: $destination,
            output: $output,
        );
    }
}
