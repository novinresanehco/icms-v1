<?php

namespace App\Core\Metric\Services;

use App\Core\Metric\Exceptions\MetricValidationException;

class MetricValidator
{
    public function validate(string $name, $value, array $tags = []): void
    {
        $this->validateName($name);
        $this->validateValue($value);
        $this->validateTags($tags);
    }

    protected function validateName(string $name): void
    {
        if (empty($name)) {
            throw new MetricValidationException('Metric name cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z0-9_.]+$/', $name)) {
            throw new MetricValidationException('Invalid metric name format');
        }
    }

    protected function validateValue($value): void
    {
        if (!is_numeric($value)) {
            throw new MetricValidationException('Metric value must be numeric');
        }
    }

    protected function validateTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                throw new MetricValidationException('Invalid tag format');
            }
        }
    }
}
