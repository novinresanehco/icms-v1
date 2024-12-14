<?php

namespace App\Core\Logger\Services;

use App\Exceptions\LoggerValidationException;

class LogValidator
{
    private array $validLevels = ['error', 'warning', 'info', 'debug'];
    
    public function validate(
        string $type,
        string $message,
        array $context,
        string $level
    ): void {
        $this->validateType($type);
        $this->validateMessage($message);
        $this->validateContext($context);
        $this->validateLevel($level);
    }

    private function validateType(string $type): void
    {
        if (empty($type)) {
            throw new LoggerValidationException('Log type cannot be empty');
        }
    }

    private function validateMessage(string $message): void
    {
        if (empty($message)) {
            throw new LoggerValidationException('Log message cannot be empty');
        }
    }

    private function validateContext(array $context): void
    {
        try {
            json_encode($context, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LoggerValidationException('Context must be JSON serializable');
        }
    }

    private function validateLevel(string $level): void
    {
        if (!in_array($level, $this->validLevels)) {
            throw new LoggerValidationException(
                "Invalid log level. Valid levels are: " . implode(', ', $this->validLevels)
            );
        }
    }
}
