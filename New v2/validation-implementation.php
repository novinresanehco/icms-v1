<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface 
{
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function validate(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $this->metrics->increment('validation.failed');
            throw new ValidationException($validator->errors());
        }

        $this->metrics->increment('validation.passed');
        return $validator->validated();
    }

    public function validateFile(UploadedFile $file, array $rules): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        if (isset($rules['mime_types'])) {
            if (!in_array($file->getMimeType(), $rules['mime_types'])) {
                throw new ValidationException('Invalid file type');
            }
        }

        if (isset($rules['max_size'])) {
            if ($file->getSize() > $rules['max_size']) {
                throw new ValidationException('File too large');
            }
        }

        $this->scanFile($file);
    }

    private function scanFile(UploadedFile $file): void
    {
        try {
            $this->security->scanFile($file);
        } catch (SecurityException $e) {
            throw new ValidationException('File failed security scan');
        }
    }
}

class ErrorHandler implements ErrorHandlerInterface
{
    private AuditLogger $audit;
    private MetricsCollector $metrics;

    public function handle(\Throwable $e): ErrorResponse
    {
        $this->logError($e);
        $this->metrics->increment('errors.' . class_basename($e));

        if ($e instanceof ValidationException) {
            return new ErrorResponse(
                'Validation failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        }

        if ($e instanceof AuthenticationException) {
            return new ErrorResponse(
                'Authentication required',
                Response::HTTP_UNAUTHORIZED
            );
        }

        if ($e instanceof AuthorizationException) {
            return new ErrorResponse(
                'Access denied',
                Response::HTTP_FORBIDDEN
            );
        }

        if ($e instanceof SecurityException) {
            return new ErrorResponse(
                'Security violation',
                Response::HTTP_BAD_REQUEST
            );
        }

        return new ErrorResponse(
            'Internal server error',
            Response::HTTP_INTERNAL_SERVER_ERROR
        );
    }

    private function logError(\Throwable $e): void
    {
        $context = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];

        if ($e instanceof ValidationException) {
            $context['errors'] = $e->errors();
        }

        $this->audit->logError($e, $context);
    }
}

class ExceptionRenderer implements ExceptionRendererInterface
{
    private SecurityManager $security;
    private ErrorHandler $handler;

    public function render(\Throwable $e): JsonResponse
    {
        $response = $this->handler->handle($e);

        return response()->json([
            'message' => $response->message,
            'errors' => $response->errors
        ], $response->status);
    }
}

class ErrorResponse
{
    public function __construct(
        public string $message,
        public int $status,
        public ?array $errors = null
    ) {}
}

class ValidationException extends \Exception
{
    private array $errors;

    public function __construct(array|string $errors)
    {
        parent::__construct('Validation failed');
        $this->errors = is_array($errors) ? $errors : ['error' => $errors];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
