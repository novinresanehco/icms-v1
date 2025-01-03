<?php

namespace App\Core\Search\Exceptions;

class SearchException extends \Exception
{
    protected array $context = [];
    
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

class IndexException extends SearchException {}

class QueryException extends SearchException {}

class ValidationException extends SearchException {}

class UnauthorizedException extends SearchException {}

class OptimizationException extends SearchException {}

class AnalysisException extends SearchException {}

class CacheException extends SearchException {}

