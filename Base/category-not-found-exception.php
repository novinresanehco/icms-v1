<?php

namespace App\Core\Exceptions;

use Exception;

class CategoryNotFoundException extends Exception
{
    public function __construct(string $message = "Category not found", int $code = 404)
    {
        parent::__construct($message, $code);
    }
}
