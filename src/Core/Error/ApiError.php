<?php

declare(strict_types=1);

namespace App\Core\Error;

use RuntimeException;
use Throwable;

final class ApiError extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly int $statusCode,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
