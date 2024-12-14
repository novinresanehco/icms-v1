<?php

namespace App\Core\Exceptions;

class SystemExceptionHandler
{
    protected LogManager $logger;
    protected SecurityManager $security;
    protected bool $debug;

    public function handle(\Throwable $e): JsonResponse
    {
        $this->logException($e);

        if ($e instanceof SecurityException) {
            return $this->handleSecurityException($e);
        }

        if ($e instanceof ValidationException) {
            return $this->handleValidationException($e);
        }

        if ($e instanceof BusinessException) {
            return $this->handleBusinessException($e);
        }

        return $this->handleCriticalException($e);
    }

    protected function logException(\Throwable $e): void
    {
        $context = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'user_id' => auth()->id(),
            'request_uri' => request()->getRequestUri(),
            'request_method' => request()->getMethod()
        ];

        if ($e instanceof SecurityException) {
            $this->logger->critical($e->getMessage(), $context);
        } else {
            $this->logger->error($e->getMessage(), $context);
        }
    }

    protected function handleSecurityException(SecurityException $e): JsonResponse
    {
        return response()->json([
            'error' => 'Security violation',
            'message' => $this->debug ? $e->getMessage() : 'Access denied'
        ], 403);
    }

    protected function handleValidationException(ValidationException $e): JsonResponse
    {
        return response()->json([
            'error' => 'Validation failed',
            'errors' => $e->getErrors()
        ], 422);
    }

    protected function handleBusinessException(BusinessException $e): JsonResponse
    {
        return response()->json([
            'error' => 'Operation failed',
            'message' => $e->getMessage()
        ], 400);
    }

    protected function handleCriticalException(\Throwable $e): JsonResponse
    {
        return response()->json([
            'error' => 'System error',
            'message' => $this->debug ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}

class SecurityException extends \Exception
{
    protected $code = 403;
}

class AuthenticationException extends SecurityException
{
    protected $code = 401;
}

class AuthorizationException extends SecurityException
{
    protected $code = 403;
}

class ValidationException extends BusinessException
{
    protected array $errors;
    protected $code = 422;

    public function __construct(array $errors, string $message = '')
    {
        parent::__construct($message ?: 'Validation failed');
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class BusinessException extends \Exception
{
    protected $code = 400;
}

class CriticalException extends \Exception
{
    protected $code = 500;
}

class DatabaseException extends CriticalException
{
}

class CacheException extends CriticalException
{
}

class ConfigurationException extends CriticalException
{
}

class TemplateException extends BusinessException
{
}

class ResourceNotFoundException extends BusinessException
{
    protected $code = 404;
}

class RateLimitException extends SecurityException
{
    protected $code = 429;
}

class IntegrityException extends SecurityException
{
    protected $code = 400;
}

trait ExceptionHandling
{
    protected function execute(callable $operation)
    {
        try {
            return DB::transaction(function() use ($operation) {
                return $operation();
            });
        } catch (ValidationException|BusinessException|SecurityException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->critical('Critical operation failed', [
                'exception' => $e,
                'operation' => get_class($this)
            ]);
            throw new CriticalException(
                'Operation failed due to system error',
                500,
                $e
            );
        }
    }
}

interface ExceptionRendererInterface
{
    public function render(\Throwable $e): JsonResponse;
    public function report(\Throwable $e): void;
}

class ApiExceptionRenderer implements ExceptionRendererInterface
{
    protected bool $debug;
    protected LogManager $logger;

    public function render(\Throwable $e): JsonResponse
    {
        $status = $this->getStatusCode($e);
        $response = [
            'error' => $this->getErrorType($e),
            'message' => $this->getMessage($e)
        ];

        if ($e instanceof ValidationException) {
            $response['errors'] = $e->getErrors();
        }

        if ($this->debug) {
            $response['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }

        return response()->json($response, $status);
    }

    public function report(\Throwable $e): void
    {
        $this->logger->error($e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function getStatusCode(\Throwable $e): int
    {
        return method_exists($e, 'getStatusCode')
            ? $e->getStatusCode()
            : ($e->getCode() ?: 500);
    }

    protected function getErrorType(\Throwable $e): string
    {
        return match(true) {
            $e instanceof SecurityException => 'security_error',
            $e instanceof ValidationException => 'validation_error',
            $e instanceof BusinessException => 'business_error',
            default => 'system_error'
        };
    }

    protected function getMessage(\Throwable $e): string
    {
        if (!$this->debug && $e instanceof CriticalException) {
            return 'Internal server error';
        }
        return $e->getMessage();
    }
}
