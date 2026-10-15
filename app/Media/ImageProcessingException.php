<?php

namespace App\Media;

use RuntimeException;

class ImageProcessingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $clientFault = true,
    ) {
        parent::__construct($message);
    }
}
