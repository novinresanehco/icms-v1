<?php

namespace App\Core\Metrics\Processors;

use App\Core\Metrics\DTOs\Metric;
use Illuminate\Support\Facades\Log;

class MetricsProcessor
{
    private array $processors = [];
    private array $errorHandlers = [];

    public function registerProcessor(string $metricType, callable $processor): void
    {
        $this->processors[$metricType] = $processor;
    }

    public function registerErrorHandler(callable $handler): void
    {
        $this->errorHandlers[] = $handler;
    }

    public function process(Metric $metric): void
    {
        try {
            if (isset($this->processors[$metric->name])) {
                $processor = $this->processors[$metric->name];
                $processor($metric);
            }
        } catch (\Exception $e) {
            $this->handleError($e, $metric);
        }
    }

    private function handleError(\Exception $e, Metric $metric): void
    {
        Log::error('Metrics processing error', [
            'metric' => $metric->toArray(),
            'error' => $e->getMessage()
        ]);

        foreach ($this->errorHandlers as $handler) {
            $handler($e, $metric);
        }
    }
}

class MetricValidator
{
    public function validate(Metric $metric): bool
    {
        return $this->validateName($metric->name) &&
               $this->validateValue($metric->value) &&
               $this->validateTags($metric->tags);
    }

    private function validateName(string $name): bool
    {
        return !empty($name) && strlen($name) <= 255;
    }

    private function validateValue($value): bool
    {
        if (is_numeric($value)) {
            return true;
        }

        if (is_string($value)) {
            return strlen($value) <= 1000;
        }

        if (is_array($value)) {
            return count($value) <= 100;
        }

        return false;
    }

    private function validateTags(array $tags): bool
    {
        if (count($tags) > 20) {
            return false;
        }

        foreach ($tags as $key => $value) {
            if (!is_string($key) || strlen($key) > 50) {
                return false;
            }
            
            if (!is_scalar($value) || strlen((string)$value) > 100) {
                return false;
            }
        }

        return true;
    }
}
