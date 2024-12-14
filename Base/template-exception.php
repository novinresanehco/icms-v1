<?php

namespace App\Core\Exceptions;

use Exception;

class TemplateException extends Exception
{
    protected $context;

    public function __construct(string $message, array $context = [], int $code = 0, ?Throwable $previous = null)
    {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
