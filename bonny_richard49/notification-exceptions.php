<?php

namespace App\Core\Notification\Analytics\Exceptions;

use Exception;

class AnalyticsException extends Exception
{
    protected array $context;

    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class DataProviderException extends AnalyticsException
{
    protected string $query;
    protected array $parameters;

    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        string $query = '',
        array $parameters = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->query = $query;
        $this->parameters = $parameters;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getQueryParameters(): array
    {
        return $this->parameters;
    }
}

class ValidationException extends AnalyticsException
{
    protected array $errors;

    public function __construct(string $message, array $errors, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getValidationErrors(): array
    {
        return $this->errors;
    }
}

class ProcessingException extends AnalyticsException
{
    protected string $processingStage;
    protected array $processingContext;

    public function __construct(
        string $message,
        string $stage,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->processingStage = $stage;
        $this->processingContext = $context;
    }

    public function getProcessingStage(): string
    {
        return $this->processingStage;
    }

    public function getProcessingContext(): array
    {
        return $this->processingContext;
    }
}
