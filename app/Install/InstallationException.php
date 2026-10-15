<?php

namespace App\Install;

use RuntimeException;
use Throwable;

class InstallationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function publicMessage(): string
    {
        return "[{$this->errorCode}] {$this->getMessage()}";
    }
}
