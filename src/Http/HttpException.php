<?php

declare(strict_types=1);

namespace MahakMixin\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly mixed $responseBody = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}
