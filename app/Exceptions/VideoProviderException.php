<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown by a VideoProvider implementation when a call to the underlying provider
 * (Bunny Stream) fails. The constructor deliberately takes a teacher-facing message
 * only — the raw provider response (status code, body, "Bunny", etc.) belongs in the
 * previous/wrapped exception for logging (`report()`), never in this exception's own
 * message, since that message is what ends up surfaced to a teacher via session errors.
 */
class VideoProviderException extends Exception
{
    public function __construct(string $teacherFacingMessage, ?Throwable $previous = null)
    {
        parent::__construct($teacherFacingMessage, 0, $previous);
    }
}
