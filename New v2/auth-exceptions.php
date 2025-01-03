<?php

namespace App\Core\Auth\Exceptions;

class AuthenticationException extends \Exception
{
    protected array $context;

    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class InvalidCredentialsException extends AuthenticationException
{
    public function __construct(array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct('Invalid credentials provided', $context, $previous);
    }
}

class InvalidSessionException extends AuthenticationException
{
    public function __construct(array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct('Invalid or expired session', $context, $previous);
    }
}

class InvalidTokenException extends AuthenticationException
{
    public function __construct(array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct('Invalid or expired token', $context, $previous);
    }
}

class SessionExpiredException extends AuthenticationException
{
    public function __construct(array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct('Session has expired', $context, $previous);
    }
}

class AccountLockedException extends AuthenticationException
{
    public function __construct(array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct('Account is locked', $context, $previous);
    }
}
