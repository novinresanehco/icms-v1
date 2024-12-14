<?php

namespace App\Core\Exceptions;

use Exception;

class ServiceException extends Exception
{
    protected $context = [];

    public function __construct(string $message, array $context = [], int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class ValidationException extends ServiceException
{
    protected $errors;

    public function __construct(array $errors, string $message = 'Validation failed', int $code = 422)
    {
        parent::__construct($message, [], $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class ResourceNotFoundException extends ServiceException
{
    public function __construct(string $resource, $identifier)
    {
        parent::__construct(
            "{$resource} not found with identifier: {$identifier}",
            ['resource' => $resource, 'identifier' => $identifier],
            404
        );
    }
}

class DuplicateResourceException extends ServiceException
{
    public function __construct(string $resource, string $field, $value)
    {
        parent::__construct(
            "{$resource} already exists with {$field}: {$value}",
            [
                'resource' => $resource,
                'field' => $field,
                'value' => $value
            ],
            409
        );
    }
}

class InvalidOperationException extends ServiceException
{
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, $context, 400);
    }
}
