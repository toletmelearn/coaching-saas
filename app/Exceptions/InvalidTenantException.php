<?php

namespace App\Exceptions;

use Exception;

class InvalidTenantException extends Exception
{
    public function __construct(string $message = 'Tenant mismatch: operation not allowed for this tenant')
    {
        parent::__construct($message);
    }
}
