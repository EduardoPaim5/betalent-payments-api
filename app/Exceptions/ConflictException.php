<?php

namespace App\Exceptions;

use RuntimeException;

class ConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = 'Conflict.',
        public readonly string $errorCode = 'conflict',
        public readonly array $details = [],
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
