<?php

namespace App\Core\Exceptions;

use Exception;

class MediaNotFoundException extends Exception
{
    public function __construct(string $message = "Media not found", int $code = 404)
    {
        parent::__construct($message, $code);
    }
}
