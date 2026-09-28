<?php

namespace App\Exceptions;

use Exception;

class MissingTenantContextException extends Exception
{
    public function __construct(string $message = 'No tenant context is set for this request')
    {
        parent::__construct($message);
    }
}
