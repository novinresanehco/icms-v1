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

    protected function logException(Throwable $e): void
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