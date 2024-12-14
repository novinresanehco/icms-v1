<?php

namespace App\Core\Exceptions;

use Exception;

class BusinessException extends Exception
{
    protected array $details = [];

    public function __construct(string $message, array $details = [], int $code = 400)
    {
        parent::__construct($message, $code);
        $this->details = $details;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
