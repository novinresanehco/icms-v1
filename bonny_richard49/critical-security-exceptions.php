// app/Core/Exceptions/Security/SecurityException.php
<?php

namespace App\Core\Exceptions\Security;

class SecurityException extends \Exception
{
    protected array $context = [];
    protected string $securityLevel = 'critical';
    protected bool $reportable = true;

    public function __construct(
        string $message = "",
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getSecurityContext(): array
    {
        return array_merge($this->context, [
            'exception' => [
                'class' => static::class,
                'message' => $this->getMessage(),
                'code' => $this->getCode(),
                'file' => $this->getFile(),
                'line' => $this->getLine()
            ],
            'security_level' => $this->securityLevel,
            'timestamp' => microtime(true),
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'ip' => request()->ip()
        ]);
    }

    public function getSecurityLevel(): string
    {
        return $this->securityLevel;
    }

    public function isReportable(): bool
    {
        return $this->reportable;
    }
}

// app/Core/Exceptions/Security/AuthenticationException.php
class AuthenticationException extends SecurityException
{
    protected string $securityLevel = 'critical';

    public function __construct(
        string $message = "Authentication failed",
        array $context = [],
        int $code = 401,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
    }
}

// app/Core/Exceptions/Security/AuthorizationException.php
class AuthorizationException extends SecurityException
{
    protected string $securityLevel = 'critical';

    public function __construct(
        string $message = "Authorization failed",
        array $context = [],
        int $code = 403,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
    }
}

// app/Core/Exceptions/Security/ValidationException.php
class ValidationException extends SecurityException
{
    protected string $securityLevel = 'error';
    protected array $errors;

    public function __construct(
        array $errors,
        string $message = "Validation failed",
        array $context = [],
        int $code = 422,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

// app/Core/Exceptions/Security/IntegrityException.php
class IntegrityException extends SecurityException
{
    protected string $securityLevel = 'critical';

    public function __construct(
        string $message = "Data integrity violation",
        array $context = [],
        int $code = 500,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
    }
}

// app/Core/Exceptions/Security/RateLimitException.php
class RateLimitException extends SecurityException
{
    protected string $securityLevel = 'warning';
    protected int $retryAfter;

    public function __construct(
        int $retryAfter,
        string $message = "Rate limit exceeded",
        array $context = [],
        int $code = 429,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
        $this->retryAfter = $retryAfter;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}

// app/Core/Exceptions/Security/EncryptionException.php
class EncryptionException extends SecurityException
{
    protected string $securityLevel = 'critical';

    public function __construct(
        string $message = "Encryption operation failed",
        array $context = [],
        int $code = 500,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
    }
}

// app/Core/Exceptions/Security/TokenException.php
class TokenException extends SecurityException
{
    protected string $securityLevel = 'critical';
    protected string $tokenType;

    public function __construct(
        string $tokenType,
        string $message = "Token validation failed",
        array $context = [],
        int $code = 401,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
        $this->tokenType = $tokenType;
    }

    public function getTokenType(): string
    {
        return $this->tokenType;
    }
}
