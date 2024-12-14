<?php
namespace App\Core\Error;
use Illuminate\Support\Facades\{DB, Cache, Log};

class CriticalErrorHandler {
    protected static array $criticalErrors = [
        'SecurityException',
        'DatabaseException',
        'AuthenticationException',
        'ValidationException'
    ];

    public function handle(\Throwable $e) {
        return DB::transaction(function() use ($e) {
            $this->logError($e);
            $this->clearSensitiveData($e);
            $this->notifyIfCritical($e);
            return $this->buildResponse($e);
        });
    }

    protected function logError(\Throwable $e): void {
        DB::table('error_logs')->insert([
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => json_encode($e->getTrace()),
            'created_at' => now()
        ]);

        if ($this->isCritical($e)) {
            Log::critical('Critical error occurred', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function clearSensitiveData(\Throwable $e): void {
        if (method_exists($e, 'context')) {
            $context = $e->context();
            unset($context['password'], $context['token']);
            Cache::tags('errors')->put(
                'error:' . $e->getCode(),
                $context,
                300
            );
        }
    }

    protected function notifyIfCritical(\Throwable $e): void {
        if ($this->isCritical($e)) {
            Cache::put('critical_error:' . time(), [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'time' => now()
            ], 3600);
        }
    }

    protected function buildResponse(\Throwable $e): array {
        $response = [
            'error' => true,
            'code' => $this->getErrorCode($e),
            'type' => $this->isCritical($e) ? 'critical' : 'error'
        ];

        if (!app()->isProduction()) {
            $response['debug'] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
        }

        return $response;
    }

    protected function getErrorCode(\Throwable $e): string {
        return match(true) {
            $e instanceof SecurityException => 'SEC_ERR',
            $e instanceof DatabaseException => 'DB_ERR',
            $e instanceof ValidationException => 'VAL_ERR',
            $e instanceof AuthenticationException => 'AUTH_ERR',
            default => 'SYS_ERR'
        };
    }

    protected function isCritical(\Throwable $e): bool {
        return in_array(get_class($e), self::$criticalErrors);
    }
}

class SecurityException extends \Exception {}
class DatabaseException extends \Exception {}
class AuthenticationException extends \Exception {}
class ValidationException extends \Exception {}

trait ErrorHandling {
    protected function executeWithErrorHandling(callable $operation) {
        try {
            return $operation();
        } catch (\Throwable $e) {
            return app(CriticalErrorHandler::class)->handle($e);
        }
    }
}

class ErrorMonitor {
    public function __construct(
        protected string $errorPrefix = 'error:',
        protected int $retentionHours = 24
    ) {}

    public function track(\Throwable $e): string {
        $errorId = uniqid('err_');
        
        Cache::put(
            $this->errorPrefix . $errorId,
            $this->serializeError($e),
            now()->addHours($this->retentionHours)
        );

        return $errorId;
    }

    public function get(string $errorId): ?array {
        return Cache::get($this->errorPrefix . $errorId);
    }

    protected function serializeError(\Throwable $e): array {
        return [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'time' => now()->toDateTimeString(),
            'memory' => memory_get_peak_usage(true)
        ];
    }

    public function cleanup(): void {
        $pattern = $this->errorPrefix . '*';
        foreach (Cache::get($pattern, []) as $key => $value) {
            if ($this->isExpired($value['time'])) {
                Cache::forget($key);
            }
        }
    }

    protected function isExpired(string $time): bool {
        return now()->diffInHours($time) > $this->retentionHours;
    }
}

class ValidationErrorHandler {
    protected array $rules = [
        'email' => 'required|email',
        'password' => 'required|min:8',
        'token' => 'required|uuid',
        'id' => 'required|integer'
    ];

    public function validate(array $data, array $rules): array {
        $errors = [];
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                $errors[$field] = $this->getErrorMessage($field, $rule);
            }
        }
        if (!empty($errors)) {
            throw new ValidationException(json_encode($errors));
        }
        return $data;
    }

    protected function validateField($value, string $rule): bool {
        foreach (explode('|', $rule) as $singleRule) {
            if (!$this->applySingleRule($value, $singleRule)) {
                return false;
            }
        }
        return true;
    }

    protected function applySingleRule($value, string $rule): bool {
        return match($rule) {
            'required' => !is_null($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value),
            'integer' => is_numeric($value) && is_int($value + 0),
            default => true
        };
    }

    protected function getErrorMessage(string $field, string $rule): string {
        return "Validation failed for $field with rule $rule";
    }
}
