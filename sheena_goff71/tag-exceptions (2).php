<?php

namespace App\Core\Tag\Exceptions;

use Exception;
use Illuminate\Validation\Validator;

class TagNotFoundException extends Exception
{
    public function __construct(string $message = "Tag not found")
    {
        parent::__construct($message);
    }
}

class TagValidationException extends Exception
{
    /**
     * @var Validator
     */
    protected Validator $validator;

    /**
     * @param Validator $validator
     * @param string $message
     */
    public function __construct(Validator $validator, string $message = "Tag validation failed")
    {
        $this->validator = $validator;
        parent::__construct($message);
    }

    /**
     * Get validation errors.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->validator->errors()->toArray();
    }
}

class TagOperationException extends Exception
{
    public function __construct(string $message = "Tag operation failed")
    {
        parent::__construct($message);
    }
}
