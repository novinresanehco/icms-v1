<?php

namespace App\Core\Exceptions;

use Exception;

class PostNotFoundException extends Exception
{
    public function __construct(string $message = "Post not found", int $code = 404)
    {
        parent::__construct($message, $code);
    }
}
