<?php

namespace App\Core\Contracts;

interface SecurityManagerInterface
{
    public function executeSecureOperation(callable $operation, array $context): mixed;
}

interface ValidationInterface
{
    public function validate(array $data, array $rules): array;
}

interface CacheInterface
{
    public function remember(string $key, callable $callback, int $ttl = null): mixed;
    public function forget(string $key): void;
    public function flush(string $pattern = ''): void;
}

interface AuditInterface
{
    public function log(string $level, string $message, array $context = []): void;
}

namespace App\Core\Exceptions;

class AuthenticationException extends \Exception
{
    protected $code = 401;
}

class AuthorizationException extends \Exception
{
    protected $code = 403;
}

class ValidationException extends \Exception
{
    protected $errors;
    protected $code = 422;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct('Validation failed');
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class SecurityException extends \Exception
{
    protected $code = 400;
}

class CmsException extends \Exception
{
    protected $code = 500;
}

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use App\Core\Exceptions\{
    AuthenticationException,
    AuthorizationException,
    ValidationException,
    SecurityException,
    CmsException
};

class Handler extends ExceptionHandler
{
    protected $dontReport = [];

    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (SecurityException $e) {
            app(LogService::class)->critical($e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
        });

        $this->renderable(function (AuthenticationException $e) {
            return response()->json([
                'error' => 'Authentication failed',
                'message' => $e->getMessage()
            ], 401);
        });

        $this->renderable(function (AuthorizationException $e) {
            return response()->json([
                'error' => 'Authorization failed',
                'message' => $e->getMessage()
            ], 403);
        });

        $this->renderable(function (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->getErrors()
            ], 422);
        });

        $this->renderable(function (SecurityException $e) {
            return response()->json([
                'error' => 'Security violation',
                'message' => $e->getMessage()
            ], 400);
        });

        $this->renderable(function (CmsException $e) {
            return response()->json([
                'error' => 'System error',
                'message' => $e->getMessage()
            ], 500);
        });
    }

    public function report(Throwable $e)
    {
        if ($this->shouldReport($e)) {
            app(LogService::class)->error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        parent::report($e);
    }
}
