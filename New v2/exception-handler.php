<?php

namespace App\Exceptions;

use App\Core\Security\AuditService;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected AuditService $auditService;

    protected $dontReport = [
        ValidationException::class,
        AuthenticationException::class
    ];

    protected $dontFlash = [
        'password',
        'password_confirmation',
        'token',
        'secret'
    ];

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            $this->logException($e);
        });

        $this->renderable(function (Throwable $e, Request $request) {
            return $this->renderException($e, $request);
        });
    }

    protected function renderValidationException(ValidationException $e, Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->validator->errors()->toArray()
        ], 422);
    }

    protected function renderAuthenticationException(AuthenticationException $e, Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated',
            'error' => $e->getMessage()
        ], 401);
    }

    protected function renderHttpException(HttpException $e, Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'status' => $e->getStatusCode()
        ], $e->getStatusCode());
    }

    protected function renderDefaultException(Throwable $e, Request $request): JsonResponse
    {
        $debug = config('app.debug');
        $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

        $response = [
            'success' => false,
            'message' => $debug ? $e->getMessage() : 'Server Error',
            'status' => $status
        ];

        if ($debug) {
            $response['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->getRelevantTrace($e)
            ];
        }

        return response()->json($response, $status);
    }

    protected function getRelevantTrace(Throwable $e): array
    {
        $trace = $e->getTrace();
        $relevantTrace = [];

        foreach ($trace as $item) {
            if (isset($item['file']) && str_starts_with($item['file'], base_path())) {
                $relevantTrace[] = [
                    'file' => str_replace(base_path(), '', $item['file']),
                    'line' => $item['line'] ?? null,
                    'function' => $item['function'] ?? null,
                    'class' => $item['class'] ?? null
                ];
            }
        }

        return array_slice($relevantTrace, 0, 10);
    }
} logException(Throwable $e): void
    {
        if ($this->shouldntReport($e)) {
            return;
        }

        $this->auditService->logSecurityEvent('exception_occurred', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->getRelevantTrace($e)
        ]);

        parent::report($e);
    }

    protected function renderException(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return $this->renderValidationException($e, $request);
        }

        if ($e instanceof AuthenticationException) {
            return $this->renderAuthenticationException($e, $request);
        }

        if ($e instanceof HttpException) {
            return $this->renderHttpException($e, $request);
        }

        return $this->renderDefaultException($e, $request);
    }

    protected function