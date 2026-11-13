<?php

namespace App\Exceptions;

use RuntimeException;

final class AuthEmailDeliveryException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct('Authentication email delivery failed: '.$category);
    }
}
