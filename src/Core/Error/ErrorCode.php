<?php

declare(strict_types=1);

namespace App\Core\Error;

final class ErrorCode
{
    public const INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';
    public const ROUTE_NOT_FOUND = 'ROUTE_NOT_FOUND';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    public const BAD_REQUEST = 'BAD_REQUEST';
    public const UNAUTHORIZED = 'UNAUTHORIZED';
    public const FORBIDDEN = 'FORBIDDEN';
    public const HTTP_ERROR = 'HTTP_ERROR';
    public const API_ERROR = 'API_ERROR';

    private function __construct()
    {
    }
}
