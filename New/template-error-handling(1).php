<?php

namespace App\Core\Template\ErrorHandling;

use App\Core\Template\Exceptions\{
    CompilationException,
    ValidationException,
    RuntimeException
};

class TemplateErrorHandler
{
    private TemplateMonitoringService $monitor;
    private array $errorStack = [];
    private bool $throwOnError;

    public function __construct(
        TemplateMonitoringService $monitor,
        bool $throwOnError = true
    ) {
        $this->monitor = $monitor;
        $this->throwOnError = $throwOnError;
        $this->registerErrorHandlers();
    }

    private function registerErrorHandlers(): void
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleFatalError']);
    }

    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $error = [
            'type' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        ];

        $this->errorStack[] = $error;
        $this->monitor->recordError($errstr, $this->getErrorLevel($errno), $error);

        if ($this->throwOnError) {
            throw new RuntimeException($errstr, $errno);
        }

        return true;
    }

    public function handleException(\Throwable $exception): void
    {
        $context = [
            'class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];

        $level = $this->getExceptionLevel($exception);
        $this->monitor->recordError($exception->getMessage(), $level, $context);

        if ($this->throwOnError) {
            throw $exception;
        }
    }

    public function handleFatalError(): void
    {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR])) {
            $this->monitor->recordError(
                $error['message'],
                'critical',
                [
                    'file' => $error['file'],
                    'line' => $error['line']
                ]
            );
        }
    }

    private function getErrorLevel(int $errno): string
    {
        return match($errno) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE => 'critical',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'notice',
            default => 'error'
        };
    }

    private function getExceptionLevel(\Throwable $exception): string
    {
        return match(true) {
            $exception instanceof CompilationException => 'error',
            $exception instanceof ValidationException => 'warning',
            $exception instanceof RuntimeException => 'error',
            default => 'critical'
        };
    }

    public function getErrorStack(): array
    {
        return $this->errorStack;
    }

    public function clearErrorStack(): void
    {
        $this->errorStack = [];
    }
}

class ErrorRecoveryService
{
    private TemplateMonitoringService $monitor;
    private string $backupPath;
    private array $recoveryStrategies;

    public function __construct(
        TemplateMonitoringService $monitor,
        string $backupPath
    ) {
        $this->monitor = $monitor;
        $this->backupPath = $backupPath;
        $this->initializeRecoveryStrategies();
    }

    private function initializeRecoveryStrategies(): void
    {
        $this->recoveryStrategies = [
            CompilationException::class => [$this, 'handleCompilationError'],
            ValidationException::class => [$this, 'handleValidationError'],
            RuntimeException::class => [$this, 'handleRuntimeError']
        ];
    }

    public function recover(\Throwable $exception, string $template): ?string
    {
        $exceptionClass = get_class($exception);
        
        if (isset($this->recoveryStrategies[$exceptionClass])) {
            return ($this->recoveryStrategies[$exceptionClass])($exception, $template);
        }
        
        return $this->handleUnknownError($exception, $template);
    }

    private function handleCompilationError(CompilationException $exception, string $template): ?string
    {
        $backupFile = $this->getBackupPath($template);
        
        if (file_exists($backupFile)) {
            $this->monitor->recordMetric('template.recovery.compilation', 1, [
                'template' => $template,
                'error' => $exception->getMessage()
            ]);
            
            return file_get_contents($backupFile);
        }
        
        return null;
    }

    private function handleValidationError(ValidationException $exception, string $template): ?string
    {
        $simplifiedTemplate = $this->simplifyTemplate($template);
        
        $this->monitor->recordMetric('template.recovery.validation', 1, [
            'template' => $template,
            'errors' => implode(', ', $exception->getErrors())
        ]);
        
        return $simplifiedTemplate;
    }

    private function handleRuntimeError(RuntimeException $exception, string $template): ?string
    {
        $fallbackTemplate = $this->getFallbackTemplate($template);
        
        $this->monitor->recordMetric('template.recovery.runtime', 1, [
            'template' => $template,
            'error' => $exception->getMessage()
        ]);
        
        return $fallbackTemplate;
    }

    private function handleUnknownError(\Throwable $exception, string $template): ?string
    {
        $this->monitor->recordError(
            "Unknown error during template processing: " . $exception->getMessage(),
            'critical',
            [
                'template' => $template,
                'exception' => get_class($exception)
            ]
        );
        
        return null;
    }

    private function getBackupPath(string $template): string
    {
        return $this->backupPath . '/' . hash('sha256', $template) . '.backup';
    }

    private function simplifyTemplate(string $template): string
    {
        // Remove complex directives and expressions
        $template = preg_replace('/@\w+\s*\(.*?\)/', '', $template);
        $template = preg_replace('/\{\{.*?\}\}/', '', $template);
        
        return $template;
    }

    private function getFallbackTemplate(string $template): string
    {
        return "<!-- Template Error: Unable to process template -->";
    }
}
