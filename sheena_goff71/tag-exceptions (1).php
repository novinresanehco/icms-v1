<?php

namespace App\Core\Tag\Exceptions;

use Exception;

class TagException extends Exception
{
    /**
     * @var array
     */
    protected array $errors = [];

    /**
     * Get validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

class TagNotFoundException extends TagException
{
    public function __construct(string $message = "Tag not found")
    {
        parent::__construct($message);
    }
}

class TagValidationException extends TagException
{
    public function __construct(array $errors = [])
    {
        $this->errors = $errors;
        parent::__construct('Tag validation failed');
    }
}

class TagRelationshipException extends TagException
{
    public function __construct(string $message = "Invalid tag relationship")
    {
        parent::__construct($message);
    }
}

class TagOperationException extends TagException
{
    public function __construct(string $message = "Tag operation failed")
    {
        parent::__construct($message);
    }
}

class TagCacheException extends TagException
{
    public function __construct(string $message = "Tag cache operation failed")
    {
        parent::__construct($message);
    }
}

class TagLockException extends TagException
{
    public function __construct(string $message = "Tag is locked for editing")
    {
        parent::__construct($message);
    }
}

class TagLimitException extends TagException
{
    public function __construct(string $message = "Tag limit exceeded")
    {
        parent::__construct($message);
    }
}

class TagPermissionException extends TagException
{
    public function __construct(string $message = "Insufficient permissions for tag operation")
    {
        parent::__construct($message);
    }
}
