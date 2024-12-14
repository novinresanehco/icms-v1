// File: app/Core/Error/Manager/ErrorManager.php
<?php

namespace App\Core\Error\Manager;

class ErrorManager
{
    protected ErrorHandler $handler;
    protected ExceptionLogger $logger;
    protected ErrorNotifier $notifier;
    protected ErrorConfig $config;

    public function handleException(\Throwable $exception): void
    {
        try {
            // Log the exception
            $this->logger->log($exception);

            // Notify if needed
            if ($this->shouldNotify($exception)) {
                $this->notifier->notify($exception);
            }

            // Handle the exception
            $this->handler->handle($exception);

        } catch (\Exception $e) {
            // Fallback error handling
            $this->handleFatalError($e);
        }
    }

    protected function shouldNotify(\Throwable $exception): bool
    {
        return $exception->getSeverity() >= $this->config->getNotificationThreshold();
    }

    protected function handleFatalError(\Exception $error): void
    {
        error_log($error->getMessage());
        http_response_code(500);
    }
}

// File: app/Core/Error/Handler/ExceptionHandler.php
<?php

namespace App\Core\Error\Handler;

class ExceptionHandler
{
    protected array $handlers = [];
    protected ErrorResponseFactory $responseFactory;
    protected ErrorContext $context;

    public function handle(\Throwable $exception): Response
    {
        $handler = $this->resolveHandler($exception);
        
        if ($handler) {
            return $handler->handle($exception);
        }

        return $this->handleUnknownException($exception);
    }

    protected function resolveHandler(\Throwable $exception): ?ExceptionHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->canHandle($exception)) {
                return $handler;
            }
        }
        
        return null;
    }

    protected function handleUnknownException(\Throwable $exception): Response
    {
        return $this->responseFactory->createErrorResponse(
            $exception,
            Response::HTTP_INTERNAL_SERVER_ERROR
        );
    }
}

// File: app/Core/Error/Logger/ExceptionLogger.php
<?php

namespace App\Core\Error\Logger;

class ExceptionLogger
{
    protected LoggerInterface $logger;
    protected ExceptionFormatter $formatter;
    protected LogContext $context;

    public function log(\Throwable $exception): void
    {
        $formattedException = $this->formatter->format($exception);
        $context = $this->buildContext($exception);

        $this->logger->error($formattedException, $context);
    }

    protected function buildContext(\Throwable $exception): array
    {
        return [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'request' => $this->context->getRequestData(),
            'user' => $this->context->getUserData()
        ];
    }
}

// File: app/Core/Error/Recovery/ErrorRecoveryManager.php
<?php

namespace App\Core\Error\Recovery;

class ErrorRecoveryManager
{
    protected StateManager $stateManager;
    protected RecoveryHandler $recoveryHandler;
    protected BackupManager $backupManager;

    public function recover(\Throwable $exception): bool
    {
        // Save current state
        $state = $this->stateManager->captureState();
        
        try {
            // Attempt recovery
            $this->recoveryHandler->recover($exception);
            
            // Verify system state
            if ($this->verifySystemState()) {
                return true;
            }

            // Rollback if verification fails
            $this->rollback($state);
            return false;

        } catch (\Exception $e) {
            // Rollback on recovery failure
            $this->rollback($state);
            return false;
        }
    }

    protected function verifySystemState(): bool
    {
        return $this->stateManager->verify();
    }

    protected function rollback(SystemState $state): void
    {
        $this->stateManager->restore($state);
    }
}
