<?php

namespace App\Core\Template\Error;

use App\Core\Template\Exceptions\TemplateException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Throwable;

class TemplateErrorHandler
{
    private array $errorTemplates;
    private bool $debugMode;
    private ErrorLogger $logger;
    private ErrorRenderer $renderer;

    public function __construct(ErrorLogger $logger, ErrorRenderer $renderer, bool $debugMode = false)
    {
        $this->logger = $logger;
        $this->renderer = $renderer;
        $this->debugMode = $debugMode;
        $this->errorTemplates = $this->getDefaultErrorTemplates();
    }

    /**
     * Handle template error
     *
     * @param Throwable $error
     * @param string $template
     * @param array $context
     * @return string
     */
    public function handle(Throwable $error, string $template, array $context = []): string
    {
        // Log the error
        $this->logger->logError($error, $template, $context);

        // Generate error response
        return $this->generateErrorResponse($error, $template, $context);
    }

    /**
     * Register custom error template
     *
     * @param int $code
     * @param string $template
     * @return void
     */
    public function registerErrorTemplate(int $code, string $template): void
    {
        $this->errorTemplates[$code] = $template;
    }

    /**
     * Generate error response
     *
     * @param Throwable $error
     * @param string $template
     * @param array $context
     * @return string
     */
    protected function generateErrorResponse(Throwable $error, string $template, array $context): string
    {
        $errorData = [
            'error' => $error,
            'template' => $template,
            'context' => $context,
            'debug' => $this->debugMode
        ];

        try {
            return $this->renderer->render(
                $this->getErrorTemplate($error),
                $errorData
            );
        } catch (Throwable $e) {
            // Fallback to basic error display if template rendering fails
            return $this->generateFallbackError($error);
        }
    }

    /**
     * Get appropriate error template
     *
     * @param Throwable $error
     * @return string
     */
    protected function getErrorTemplate(Throwable $error): string
    {
        $code = $this->getErrorCode($error);
        return $this->errorTemplates[$code] ?? $this->errorTemplates[500];
    }

    /**
     * Get error code from exception
     *
     * @param Throwable $error
     * @return int
     */
    protected function getErrorCode(Throwable $error): int
    {
        if ($error instanceof TemplateException) {
            return $error->getCode() ?: 500;
        }
        return 500;
    }

    /**
     * Generate fallback error display
     *
     * @param Throwable $error
     * @return string
     */
    protected function generateFallbackError(Throwable $error): string
    {
        $message = $this->debugMode ? 
            $error->getMessage() : 
            'An error occurred while processing the template.';

        return <<<HTML
        <!DOCTYPE html>
        <html>
            <head>
                <title>Template Error</title>
                <style>
                    body { font-family: sans-serif; padding: 20px; }
                    .error { color: #721c24; background: #f8d7da; padding: 20px; border-radius: 4px; }
                </style>
            </head>
            <body>
                <div class="error">
                    <h1>Template Error</h1>
                    <p>{$message}</p>
                </div>
            </body>
        </html>
        HTML;
    }

    /**
     * Get default error templates
     *
     * @return array
     */
    protected function getDefaultErrorTemplates(): array
    {
        return [
            404 => 'errors.template-not-found',
            500 => 'errors.template-error',
            400 => 'errors.template-invalid',
        ];
    }
}

class ErrorLogger
{
    /**
     * Log template error
     *
     * @param Throwable $error
     * @param string $template
     * @param array $context
     * @return void
     */
    public function logError(Throwable $error, string $template, array $context): void
    {
        $data = [
            'template' => $template,
            'error' => [
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString()
            ],
            'context' => $this->sanitizeContext($context)
        ];

        Log::error('Template Error', $data);
    }

    /**
     * Sanitize context data for logging
     *
     * @param array $context
     * @return array
     */
    protected function sanitizeContext(array $context): array
    {
        return array_map(function ($value) {
            if (is_object($value)) {
                return get_class($value);
            }
            if (is_resource($value)) {
                return get_resource_type($value);
            }
            return $value;
        }, $context);
    }
}

class ErrorRenderer
{
    private bool $debugMode;

    public function __construct(bool $debugMode = false)
    {
        $this->debugMode = $debugMode;
    }

    /**
     * Render error template
     *
     * @param string $template
     * @param array $data
     * @return string
     */
    public function render(string $template, array $data): string
    {
        $data['debug'] = $this->debugMode;
        
        return View::make($template, $data)->render();
    }

    /**
     * Format stack trace for display
     *
     * @param Throwable $error
     * @return string
     */
    public function formatStackTrace(Throwable $error): string
    {
        if (!$this->debugMode) {
            return '';
        }

        $trace = '';
        foreach ($error->getTrace() as $t) {
            $trace .= sprintf(
                "#%s %s(%s): %s%s%s()\n",
                $t['line'] ?? '?',
                $t['file'] ?? '?',
                $t['line'] ?? '?',
                $t['class'] ?? '',
                $t['type'] ?? '',
                $t['function'] ?? ''
            );
        }
        return $trace;
    }
}

class TemplateErrorCollection
{
    private array $errors = [];

    /**
     * Add an error
     *
     * @param string $template
     * @param Throwable $error
     * @return void
     */
    public function add(string $template, Throwable $error): void
    {
        $this->errors[] = [
            'template' => $template,
            'error' => $error,
            'timestamp' => time()
        ];
    }

    /**
     * Get all errors
     *
     * @return array
     */
    public function all(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for specific template
     *
     * @param string $template
     * @return array
     */
    public function forTemplate(string $template): array
    {
        return array_filter($this->errors, function ($error) use ($template) {
            return $error['template'] === $template;
        });
    }

    /**
     * Clear all errors
     *
     * @return void
     */
    public function clear(): void
    {
        $this->errors = [];
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Error\TemplateErrorHandler;
use App\Core\Template\Error\ErrorLogger;
use App\Core\Template\Error\ErrorRenderer;

class TemplateErrorServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(TemplateErrorHandler::class, function ($app) {
            return new TemplateErrorHandler(
                new ErrorLogger(),
                new ErrorRenderer($app->environment('local')),
                $app->environment('local')
            );
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $handler = $this->app->make(TemplateErrorHandler::class);

        // Register custom error templates
        $handler->registerErrorTemplate(403, 'errors.template-forbidden');
        $handler->registerErrorTemplate(422, 'errors.template-invalid-data');
    }
}
