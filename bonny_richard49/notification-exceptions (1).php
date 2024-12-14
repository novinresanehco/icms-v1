<?php

namespace App\Core\Notification\Exceptions;

use Exception;
use Illuminate\Http\Response;

abstract class NotificationException extends Exception
{
    protected $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
    protected $errorCode;
    protected $context = [];

    public function __construct(string $message = "", array $context = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
        $this->errorCode = $this->getErrorCode();
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getErrorCode(): string
    {
        return static::class;
    }

    public function render($request)
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'context' => $this->context
            ]
        ], $this->statusCode);
    }
}

class NotificationDeliveryException extends NotificationException
{
    protected $statusCode = Response::HTTP_SERVICE_UNAVAILABLE;

    public function __construct(string $channel, string $reason, array $context = [])
    {
        $message = "Failed to deliver notification through {$channel}: {$reason}";
        parent::__construct($message, array_merge(['channel' => $channel], $context));
    }
}

class NotificationTemplateNotFoundException extends NotificationException
{
    protected $statusCode = Response::HTTP_NOT_FOUND;

    public function __construct(string $templateId, array $context = [])
    {
        $message = "Notification template not found: {$templateId}";
        parent::__construct($message, array_merge(['template_id' => $templateId], $context));
    }
}

class NotificationChannelNotConfiguredException extends NotificationException
{
    protected $statusCode = Response::HTTP_PRECONDITION_FAILED;

    public function __construct(string $channel, array $context = [])
    {
        $message = "Notification channel not properly configured: {$channel}";
        parent::__construct($message, array_merge(['channel' => $channel], $context));
    }
}

class NotificationValidationException extends NotificationException
{
    protected $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY;

    public function __construct(array $errors, array $context = [])
    {
        $message = "Notification validation failed";
        parent::__construct($message, array_merge(['errors' => $errors], $context));
    }
}

class NotificationRateLimitExceededException extends NotificationException
{
    protected $statusCode = Response::HTTP_TOO_MANY_REQUESTS;

    public function __construct(string $channel, int $retryAfter, array $context = [])
    {
        $message = "Rate limit exceeded for channel: {$channel}";
        parent::__construct(
            $message, 
            array_merge(
                [
                    'channel' => $channel,
                    'retry_after' => $retryAfter
                ],
                $context
            )
        );
    }

    public function render($request)
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'context' => $this->context
            ]
        ], $this->statusCode)
        ->header('Retry-After', $this->context['retry_after']);
    }
}

class NotificationPreferenceException extends NotificationException
{
    protected $statusCode = Response::HTTP_BAD_REQUEST;

    public function __construct(string $reason, array $context = [])
    {
        $message = "Invalid notification preference: {$reason}";
        parent::__construct($message, $context);
    }
}

class NotificationQueueException extends NotificationException
{
    protected $statusCode = Response::HTTP_SERVICE_UNAVAILABLE;

    public function __construct(string $reason, array $context = [])
    {
        $message = "Notification queue error: {$reason}";
        parent::__construct($message, $context);
    }
}

class NotificationSchedulingException extends NotificationException
{
    protected $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY;

    public function __construct(string $reason, array $context = [])
    {
        $message = "Notification scheduling error: {$reason}";
        parent::__construct($message, $context);
    }
}