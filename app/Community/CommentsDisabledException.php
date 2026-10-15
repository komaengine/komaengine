<?php

namespace App\Community;

use RuntimeException;

/**
 * Thrown by the comment domain services when commenting is disabled site-wide.
 * Mapped to an HTTP 403 centrally (bootstrap/app.php) so the service stays free
 * of HTTP concerns.
 */
class CommentsDisabledException extends RuntimeException
{
    public function __construct(string $message = 'Comments are disabled.')
    {
        parent::__construct($message);
    }
}
