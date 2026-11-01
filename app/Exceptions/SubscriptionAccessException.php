<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SubscriptionAccessException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus, public readonly array $details = [])
    {
        parent::__construct($errorCode);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['code' => $this->errorCode, 'message' => $this->errorCode, ...$this->details], $this->httpStatus)
            ->header('Cache-Control', 'private, no-store');
    }

    public function report(): bool
    {
        return false;
    }
}
