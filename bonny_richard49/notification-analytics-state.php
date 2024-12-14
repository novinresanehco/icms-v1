<?php

namespace App\Core\Notification\Analytics\State;

class AnalyticsStateManager
{
    private array $states = [];
    private array $history = [];
    private array $snapshots = [];
    
    public function setState(string $key, $value): void
    {
        $this->states[$key] = $value;
        $this->recordHistory($key, $value);
    }

    public function getState(string $key)
    {
        return $this->states[$key] ?? null;
    }

    public function createSnapshot(string $name): string
    {
        $snapshotId = $this->generateSnapshotId();
        $this->snapshots[$snapshotId] = [
            'name' => $name,
            'states' => $this->states,
            'timestamp' => time()
        ];
        return $snapshotId;
    }

    public function restoreSnapshot(string $snapshotId): bool
    {
        if (!isset($this->snapshots[$snapshotId])) {
            return false;
        }

        $this->states = $this->snapshots[$snapshotId]['states'];
        return true;
    }

    public function getHistory(string $key): array
    {
        return $this->history[$key] ?? [];
    }

    public function clearHistory(string $key): void
    {
        unset($this->history[$key]);
    }

    public function getSnapshots(): array
    {
        return array_map(function($snapshot) {
            return [
                'name' => $snapshot['name'],
                'timestamp' => $snapshot['timestamp']
            ];
        }, $this->snapshots);
    }

    private function recordHistory(string $key, $value): void
    {
        if (!isset($this->history[$key])) {
            $this->history[$key] = [];
        }

        $this->history[$key][] = [
            'value' => $value,
            'timestamp' => time()
        ];
    }

    private function generateSnapshotId(): string
    {
        return uniqid('snapshot_', true);
    }
}

class StateValidator
{
    private array $rules;
    private array $errors = [];

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function validate(array $states): bool
    {
        $this->errors = [];

        foreach ($this->rules as $key => $rules) {
            if (isset($states[$key])) {
                $this->validateState($key, $states[$key], $rules);
            } elseif ($rules['required'] ?? false) {
                $this->errors[$key] = "Required state '{$key}' is missing";
            }
        }

        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function validateState(string $key, $value, array $rules): void
    {
        foreach ($rules as $rule => $constraint) {
            switch ($rule) {
                case 'type':
                    if (!$this->validateType($value, $constraint)) {
                        $this->errors[$key][] = "Invalid type. Expected {$constraint}";
                    }
                    break;

                case 'min':
                    if (is_numeric($value) && $value < $constraint) {
                        $this->errors[$key][] = "Value must be at least {$constraint}";
                    }
                    break;

                case 'max':
                    if (is_numeric($value) && $value > $constraint) {
                        $this->errors[$key][] = "Value must not exceed {$constraint}";
                    }
                    break;

                case 'pattern':
                    if (!preg_match($constraint, $value)) {
                        $this->errors[$key][] = "Value does not match required pattern";
                    }
                    break;
            }
        }
    }

    private function validateType($value, string $expectedType): bool
    {
        switch ($expectedType) {
            case 'string':
                return is_string($value);
            case 'integer':
                return is_int($value);
            case 'float':
                return is_float($value);
            case 'boolean':
                return is_bool($value);
            case 'array':
                return is_array($value);
            default:
                return false;
        }
    }
}

class StateTransformer
{
    private array $transformers = [];

    public function registerTransformer(string $type, callable $transformer): void
    {
        $this->transformers[$type] = $transformer;
    }

    public function transform($value, string $type)
    {
        if (!isset($this->transformers[$type])) {
            throw new \InvalidArgumentException("No transformer registered for type: {$type}");
        }

        return ($this->transformers[$type])($value);
    }

    public function transformBatch(array $values, string $type): array
    {
        return array_map(function($value) use ($type) {
            return $this->transform($value, $type);
        }, $values);
    }
}
