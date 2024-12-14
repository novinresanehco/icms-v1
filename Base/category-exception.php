<?php

namespace App\Core\Exceptions;

class CategoryException extends \Exception
{
    protected $message;
    protected $code;

    public function __construct(string $message = '', int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
