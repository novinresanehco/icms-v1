<?php

namespace App\Core\Exceptions;

class SecurityException extends \Exception
{
    protected array $context;
    
    public function __construct(string $message, array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class ValidationException extends SecurityException {}

class AuthenticationException extends SecurityException {}

class AuthorizationException extends SecurityException {}

class TokenException extends SecurityException {}

class CacheException extends \Exception {}

class ContentException extends \Exception {}

class TemplateException extends \Exception {}

class DatabaseException extends \Exception {}
