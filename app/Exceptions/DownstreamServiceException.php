<?php

namespace App\Exceptions;

use RuntimeException;

class DownstreamServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }
}
