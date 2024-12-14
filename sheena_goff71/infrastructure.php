<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Log, Redis};
use Psr\Log\LoggerInterface;

class CacheService
{
    private $redis;
    private $prefix = 'cms:';
    private $defaultTtl = 3600;

    public function __construct()
    {
        $this->redis = Redis::connection();
    }

    public function remember(string $key, callable $callback, int $ttl = null): mixed
    {
        $key = $this->prefix . $key;
        $value = $this->redis->get($key);

        if ($value !== null) {
            return unserialize($value);
        }

        $value = $callback();
        $this->redis->setex($key, $ttl ?? $this->defaultTtl, serialize($value));
        return $value;
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function flush(string $pattern = ''): void
    {
        $keys = $this->redis->keys($this->prefix . $pattern . '*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }
}

class ValidationService
{
    private array $customRules = [];

    public function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleSet) {
            $ruleArray = explode('|', $ruleSet);
            foreach ($ruleArray as $rule) {
                if (!$this->validateField($data[$field] ?? null, $rule)) {
                    $errors[$field][] = $this->getErrorMessage($field, $rule);
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    private function validateField($value, string $rule): bool
    {
        if (isset($this->customRules[$rule])) {
            return $this->customRules[$rule]($value);
        }

        return match ($rule) {
            'required' => $value !== null && $value !== '',
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            default => $this->parseComplexRule($value, $rule)
        };
    }

    private function parseComplexRule($value, string $rule): bool
    {
        if (strpos($rule, ':') === false) {
            return true;
        }

        [$ruleName, $parameter] = explode(':', $rule, 2);

        return match ($ruleName) {
            'min' => is_string($value) ? strlen($value) >= (int)$parameter : $value >= (int)$parameter,
            'max' => is_string($value) ? strlen($value) <= (int)$parameter : $value <= (int)$parameter,
            'between' => $this->validateBetween($value, $parameter),
            'in' => in_array($value, explode(',', $parameter)),
            default => true
        };
    }

    private function validateBetween($value, string $parameter): bool
    {
        [$min, $max] = explode(',', $parameter);
        return $value >= (int)$min && $value <= (int)$max;
    }

    public function addRule(string $name, callable $callback): void
    {
        $this->customRules[$name] = $callback;
    }
}

class LogService implements LoggerInterface
{
    private string $logPath;
    private array $levels = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7
    ];

    public function __construct(string $logPath = null)
    {
        $this->logPath = $logPath ?? storage_path('logs/cms.log');
    }

    public function log($level, string $message, array $context = []): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];

        $formattedEntry = json_encode($logEntry) . PHP_EOL;
        file_put_contents($this->logPath, $formattedEntry, FILE_APPEND | LOCK_EX);

        if ($this->levels[$level] <= $this->levels['error']) {
            $this->alertCriticalError($logEntry);
        }
    }

    private function alertCriticalError(array $logEntry): void
    {
        // Critical error notification would go here
        // Keeping core functionality only for time constraints
    }

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}
