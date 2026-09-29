<?php

declare(strict_types=1);

namespace MahakMixin\Client;

use RuntimeException;

final class MahakApiException extends RuntimeException
{
    /** @param array<string,mixed> $responseBody */
    public function __construct(
        string $message,
        public readonly array $responseBody,
    ) {
        parent::__construct($message);
    }
}
