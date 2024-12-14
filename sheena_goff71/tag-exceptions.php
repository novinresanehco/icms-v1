<?php

namespace App\Core\Tag\Exceptions;

use Exception;

class TagException extends Exception
{
    protected array $errors = [];

    public function __construct(string $message = '', array $errors = [], int $code = 0)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class TagNotFoundException extends TagException
{
    public function __construct(int $id)
    {
        parent::__construct("Tag not found with ID: {$id}");
    }
}

class TagValidationException extends TagException
{
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message, $errors);
    }
}

class TagRelationshipException extends TagException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

class TagOperationException extends TagException
{
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, $context);
    }
}
