<?php

namespace App\Exceptions;

use RuntimeException;

class WorkflowConflictException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
