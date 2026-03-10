<?php

namespace App\Services\Payments\Gateways;

use App\Enums\GatewayErrorType;

class GatewayResult
{
    public function __construct(
        public bool $success,
        public ?string $externalId,
        public string $status,
        public ?string $message,
        public ?string $errorType,
        public array $rawResponse,
        public ?int $statusCode = null,
        public bool $shouldStopFallback = false,
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'externalId' => $this->externalId,
            'status' => $this->status,
            'message' => $this->message,
            'errorType' => $this->errorType,
            'statusCode' => $this->statusCode,
            'shouldStopFallback' => $this->shouldStopFallback,
            'rawResponse' => $this->rawResponse,
        ];
    }

    public static function technicalFailure(
        string $message = 'Gateway request failed.',
        array $rawResponse = [],
        ?int $statusCode = null,
    ): self {
        return new self(
            false,
            null,
            'declined',
            $message,
            GatewayErrorType::TECHNICAL->value,
            $rawResponse,
            $statusCode,
            false,
        );
    }

    public static function ambiguousFailure(
        string $message = 'Gateway returned an ambiguous approval result. Manual review is required.',
        array $rawResponse = [],
        ?int $statusCode = null,
    ): self {
        return new self(
            false,
            null,
            'ambiguous',
            $message,
            GatewayErrorType::AMBIGUOUS->value,
            $rawResponse,
            $statusCode,
            true,
        );
    }
}
