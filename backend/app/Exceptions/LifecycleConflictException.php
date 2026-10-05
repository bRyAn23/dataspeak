<?php

namespace App\Exceptions;

/** Invalid lifecycle transition or precondition (HTTP 409). */
class LifecycleConflictException extends ApiException
{
    public function __construct(string $message, string $code = 'LIFECYCLE_CONFLICT', array $details = [])
    {
        parent::__construct($code, $message, 409, $details);
    }
}
