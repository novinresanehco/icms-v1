<?php

namespace App\Core\Validation;

interface ValidatorInterface
{
    public function validate($value): bool;
    public function getMessage(): string;
}

class RequiredValidator implements ValidatorInterface
{
    public function validate($value): bool
    {
        return !empty($value);
    }

    public function getMessage(): string
    {
        return 'This field is required';
    }
}

class StringValidator implements ValidatorInterface 
{
    public function validate($value): bool
    {
        return is_string($value);
    }

    public function getMessage(): string
    {
        return 'This field must be a string';
    }
}

class NumericValidator implements ValidatorInterface
{
    public function validate($value): bool 
    {
        return is_numeric($value);
    }

    public function getMessage(): string
    {
        return 'This field must be numeric';
    }
}

class ExistsValidator implements ValidatorInterface
{
    private string $table;
    private string $column;
    private Repository $repository;

    public function validate($value): bool
    {
        return $this->repository->exists($this->table, $this->column, $value);
    }

    public function getMessage(): string
    {
        return "Record does not exist in {$this->table}";
    }
}

class UniqueValidator implements ValidatorInterface
{
    private string $table;
    private string $column;
    private Repository $repository;
    private ?int $exceptId;

    public function validate($value): bool
    {
        return $this->repository->isUnique(
            $this->table,
            $this->column,
            $value,
            $this->exceptId
        );
    }

    public function getMessage(): string 
    {
        return "Value must be unique in {$this->table}";
    }
}

class EmailValidator implements ValidatorInterface
{
    public function validate($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function getMessage(): string
    {
        return 'Invalid email address';
    }
}

class UrlValidator implements ValidatorInterface
{
    public function validate($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    public function getMessage(): string
    {
        return 'Invalid URL';
    }
}

class FileValidator implements ValidatorInterface
{
    private array $allowedTypes;
    private int $maxSize;

    public function validate($value): bool
    {
        if (!($value instanceof UploadedFile)) {
            return false;
        }

        if ($value->getSize() > $this->maxSize) {
            return false;
        }

        return in_array($value->getMimeType(), $this->allowedTypes);
    }

    public function getMessage(): string
    {
        return 'Invalid file';
    }
}

class DateValidator implements ValidatorInterface 
{
    public function validate($value): bool
    {
        return strtotime($value) !== false;
    }

    public function getMessage(): string
    {
        return 'Invalid date';
    }
}

class JsonValidator implements ValidatorInterface
{
    public function validate($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public function getMessage(): string
    {
        return 'Invalid JSON';
    }
}

class IpValidator implements ValidatorInterface
{
    public function validate($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    public function getMessage(): string
    {
        return 'Invalid IP address';
    }
}

class MacAddressValidator implements ValidatorInterface
{
    public function validate($value): bool
    {
        return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $value) === 1;
    }

    public function getMessage(): string
    {
        return 'Invalid MAC address';
    }
}

class ValidationException extends \Exception
{
    private array $errors;

    public function __construct(string $message = '', array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}