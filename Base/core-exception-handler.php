<?php

namespace App\Core\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use App\Core\Logging\LogManager;
use Throwable;

class CoreExceptionHandler extends ExceptionHandler
{
    protected $logManager;
    
    protected $dontReport = [
        ValidationException::class,
    ];

    protected $errorCodes = [
        'validation_error' => 422,
        'business_error' => 400,
        'not_found' => 404,
        'unauthorized' => 401,
        'forbidden' => 403,
        'server_error' => 500,
    ];

    public function __construct(LogManager $logManager)
    {
        parent::__construct(app());
        $this->logManager = $logManager;
    }

    public function render($request, Throwable $e): Response|JsonResponse
    {
        if ($request->expectsJson()) {
            return $this->handleApiException($e);
        }

        return $this->handleWebException($e);
    }

    protected function handleApiException(Throwable $e): JsonResponse
    {
        $error = $this->convertExceptionToArray($e);

        if ($e instanceof ValidationException) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                'code' => $this->errorCodes['validation_error']
            ], $this->errorCodes['validation_error']);
        }

        if ($e instanceof BusinessException) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $this->errorCodes['business_error'],
                'details' => $e->getDetails()
            ], $this->errorCodes['business_error']);
        }

        // Log unexpected errors
        $this->logManager->error('Unexpected error occurred', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'status' => 'error',
            'message' => $this->isDebugMode() ? $e->getMessage() : 'An unexpected error occurred',
            'code' => $this->errorCodes['server_error']
        ], $this->errorCodes['server_error']);
    }

    protected function handleWebException(Throwable $e): Response
    {
        if ($e instanceof ValidationException) {
            return back()
                ->withErrors($e->errors())
                ->withInput();
        }

        $this->logManager->error('Web exception occurred', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->view('errors.500', [
            'message' => $this->isDebugMode() ? $e->getMessage() : 'An unexpected error occurred'
        ], $this->errorCodes['server_error']);
    }

    protected function isDebugMode(): bool
    {
        return config('app.debug', false);
    }

    protected function convertExceptionToArray(Throwable $e): array
    {
        return [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $this->isDebugMode() ? $e->getTraceAsString() : []
        ];
    }
}
