<?php

namespace App\Exceptions;

class SecurityException extends \Exception
{
    protected array $context;
    protected bool $isCritical;

    public function __construct(
        string $message = "",
        array $context = [],
        bool $isCritical = false,
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
        $this->isCritical = $isCritical;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function isCritical(): bool
    {
        return $this->isCritical;
    }
}

class CriticalSystemException extends \Exception
{
    protected array $systemState;
    protected int $severity;

    public function __construct(
        string $message = "",
        array $systemState = [],
        int $severity = 1,
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->systemState = $systemState;
        $this->severity = $severity;
    }

    public function getSystemState(): array
    {
        return $this->systemState;
    }

    public function getSeverity(): int
    {
        return $this->severity;
    }
}

class ValidationException extends \Exception
{
    protected array $errors;
    protected string $field;

    public function __construct(
        string $message = "",
        array $errors = [],
        string $field = "",
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
        $this->field = $field;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getField(): string
    {
        return $this->field;
    }
}

class DatabaseException extends \Exception
{
    protected string $query;
    protected array $bindings;
    protected bool $isCorruption;

    public function __construct(
        string $message = "",
        string $query = "",
        array $bindings = [],
        bool $isCorruption = false,
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->query = $query;
        $this->bindings = $bindings;
        $this->isCorruption = $isCorruption;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function isCorruption(): bool
    {
        return $this->isCorruption;
    }
}

class CacheException extends \Exception
{
    protected string $key;
    protected string $operation;
    protected bool $isSystemFailure;

    public function __construct(
        string $message = "",
        string $key = "",
        string $operation = "",
        bool $isSystemFailure = false,
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->key = $key;
        $this->operation = $operation;
        $this->isSystemFailure = $isSystemFailure;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function isSystemFailure(): bool
    {
        return $this->isSystemFailure;
    }
}

class StorageException extends \Exception
{
    protected string $path;
    protected string $operation;
    protected bool $isPermissionError;

    public function __construct(
        string $message = "",
        string $path = "",
        string $operation = "",
        bool $isPermissionError = false,
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->path = $path;
        $this->operation = $operation;
        $this->isPermissionError = $isPermissionError;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function isPermissionError(): bool
    {
        return $this->isPermissionError;
    }
}
