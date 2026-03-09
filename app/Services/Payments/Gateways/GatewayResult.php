<?php

namespace App\Services\Payments\Gateways;

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
            'rawResponse' => $this->rawResponse,
        ];
    }
}
