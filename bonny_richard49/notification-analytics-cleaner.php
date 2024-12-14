<?php

namespace App\Core\Notification\Analytics\Cleaner;

class DataCleaner
{
    private array $cleaners = [];
    private array $validators = [];
    private array $metrics = [];

    public function addCleaner(string $type, callable $cleaner): void
    {
        $this->cleaners[$type] = $cleaner;
    }

    public function addValidator(string $type, callable $validator): void
    {
        $this->validators[$type] = $validator;
    }

    public function clean(array $data): array
    {
        $startTime = microtime(true);
        $result = [];

        foreach ($data as $key => $value) {
            $type = $this->determineType($value);
            if (isset($this->cleaners[$type])) {
                try {
                    $cleaned = ($this->cleaners[$type])($value);
                    if ($this->validate($cleaned, $type)) {
                        $result[$key] = $cleaned;
                    }
                } catch (\Exception $e) {
                    $this->recordError($type, $e);
                }
            } else {
                $result[$key] = $value;
            }
        }

        $this->recordMetrics('clean', microtime(true) - $startTime, count($data), count($result));
        return $result;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function determineType($value): string
    {
        if (is_array($value)) return 'array';
        if (is_numeric($value)) return 'numeric';
        if (is_string($value)) return 'string';
        if (is_bool($value)) return 'boolean';
        return 'unknown';
    }

    private function validate($value, string $type): bool
    {
        if (!isset($this->validators[$type])) {
            return true;
        }

        try {
            return ($this->validators[$type])($value);
        } catch (\Exception $e) {
            $this->recordError($type, $e);
            return false;
        }
    }

    private function recordMetrics(string $operation, float $duration, int $inputCount, int $outputCount): void
    {
        if (!isset($this->metrics[$operation])) {
            $this->metrics[$operation] = [
                'total_operations' => 0,
                'total_duration' => 0,
                'total_input_items' => 0,
                'total_output_items' => 0,
                'errors' => 0
            ];
        }

        $this->metrics[$operation]['total_operations']++;
        $this->metrics[$operation]['total_duration'] += $duration;
        $this->metrics[$operation]['total_input_items'] += $inputCount;
        $this->metrics[$operation]['total_output_items'] += $outputCount;
    }

    private function recordError(string $type, \Exception $error): void
    {
        if (!isset($this->metrics['errors'][$type])) {
            $this->metrics['errors'][$type] = [
                'count' => 0,
                'last_error' => null
            ];
        }

        $this->metrics['errors'][$type]['count']++;
        $this->metrics['errors'][$type]['last_error'] = [
            'message' => $error->getMessage(),
            'time' => time()
        ];
    }
}

class StringCleaner
{
    public static function clean(string $value, array $options = []): string
    {
        $value = trim($value);
        
        if ($options['lowercase'] ?? false) {
            $value = strtolower($value);
        }
        
        if ($options['remove_special_chars'] ?? false) {
            $value = preg_replace('/[^A-Za-z0-9\s]/', '', $value);
        }
        
        if ($options['normalize_whitespace'] ?? false) {
            $value = preg_replace('/\s+/', ' ', $value);
        }
        
        return $value;
    }
}

class NumericCleaner
{
    public static function clean($value, array $options = []): float
    {
        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
            $value = floatval($value);
        }
        
        if (isset($options['min']) && $value < $options['min']) {
            $value = $options['min'];
        }
        
        if (isset($options['max']) && $value > $options['max']) {
            $value = $options['max'];
        }
        
        if (isset($options['precision'])) {
            $value = round($value, $options['precision']);
        }
        
        return $value;
    }
}

class ArrayCleaner
{
    public static function clean(array $value, array $options = []): array
    {
        if ($options['remove_empty'] ?? false) {
            $value = array_filter($value, function($v) {
                return !empty($v) || $v === 0 || $v === false;
            });
        }
        
        if ($options['unique'] ?? false) {
            $value = array_unique($value, SORT_REGULAR);
        }
        
        if ($options['sort'] ?? false) {
            sort($value);
        }
        
        return $value;
    }
}

class DateTimeCleaner
{
    public static function clean($value, array $options = []): string
    {
        $format = $options['format'] ?? 'Y-m-d H:i:s';
        
        if (is_numeric($value)) {
            $date = new \DateTime('@' . $value);
        } elseif (is_string($value)) {
            $date = new \DateTime($value);
        } else {
            throw new \InvalidArgumentException('Invalid date value');
        }
        
        return $date->format($format);
    }
}
