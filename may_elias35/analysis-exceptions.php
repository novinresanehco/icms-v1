<?php

namespace App\Core\Audit\Exceptions;

class AnalysisException extends \Exception
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

class DataProcessingException extends AnalysisException
{
    public function __construct(string $message, array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Data Processing Error: {$message}", $context, $code, $previous);
    }
}

class ValidationException extends AnalysisException
{
    private array $validationErrors;

    public function __construct(array $errors, array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Validation Failed: " . implode(", ", $errors),
            $context,
            $code,
            $previous
        );
        $this->validationErrors = $errors;
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
}

class StatisticalAnalysisException extends AnalysisException
{
    public function __construct(string $message, array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Statistical Analysis Error: {$message}", $context, $code, $previous);
    }
}

class PatternDetectionException extends AnalysisException
{
    public function __construct(string $message, array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Pattern Detection Error: {$message}", $context, $code, $previous);
    }
}

class TrendAnalysisException extends AnalysisException
{
    public function __construct(string $message, array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Trend Analysis Error: {$message}", $context, $code, $previous);
    }
}

class AnomalyDetectionException extends AnalysisException
{
    public function __construct(string $message, array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Anomaly Detection Error: {$message}", $context, $code, $previous);
    }
}

class ConfigurationException extends AnalysisException
{
    public function __construct(string $message, array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Configuration Error: {$message}", $context, $code, $previous);
    }
}
