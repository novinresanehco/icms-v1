<?php

namespace App\Core\Analytics\Services;

use App\Core\Analytics\Exceptions\AnalyticsValidationException;

class AnalyticsValidator
{
    public function validate(string $event, array $properties = []): void
    {
        $this->validateEvent($event);
        $this->validateProperties($properties);
    }

    public function validateTraits(array $traits): void
    {
        foreach ($traits as $key => $value) {
            $this->validateTraitKey($key);
            $this->validateTraitValue($value);
        }
    }

    protected function validateEvent(string $event): void
    {
        if (empty($event)) {
            throw new AnalyticsValidationException('Event name cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $event)) {
            throw new AnalyticsValidationException('Invalid event name format');
        }

        $allowedEvents = config('analytics.allowed_events', []);
        if (!empty($allowedEvents) && !in_array($event, $allowedEvents)) {
            throw new AnalyticsValidationException("Invalid event: {$event}");
        }
    }

    protected function validateProperties(array $properties): void
    {
        foreach ($properties as $key => $value) {
            $this->validatePropertyKey($key);
            $this->validatePropertyValue($value);
        }
    }

    protected function validatePropertyKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            throw new AnalyticsValidationException("Invalid property key: {$key}");
        }
    }

    protected function validatePropertyValue($value): void
    {
        if (!is_scalar($value) && !is_array($value)) {
            throw new AnalyticsValidationException('Property values must be scalar or array');
        }
    }

    protected function validateTraitKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            throw new AnalyticsValidationException("Invalid trait key: {$key}");
        }
    }

    protected function validateTraitValue($value): void
    {
        if (!is_scalar($value)) {
            throw new AnalyticsValidationException('Trait values must be scalar');
        }
    }
}
