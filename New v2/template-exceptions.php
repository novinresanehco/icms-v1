<?php

namespace App\Core\Template\Exceptions;

class TemplateException extends \Exception
{
    protected array $context;
    
    public function __construct(
        string $message,
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class ValidationException extends TemplateException
{
    private array $errors;
    
    public function __construct(
        string $message,
        array $errors = [],
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class SecurityException extends TemplateException
{
    private string $resource;
    
    public function __construct(
        string $message,
        string $resource = '',
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
        $this->resource = $resource;
    }

    public function getResource(): string
    {
        return $this->resource;
    }
}

class CompilationException extends TemplateException
{
    private string $template;
    private int $line;
    
    public function __construct(
        string $message,
        string $template,
        int $line = 0,
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
        $this->template = $template;
        $this->line = $line;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function getLine(): int
    {
        return $this->line;
    }
}

class ThemeException extends TemplateException
{
    private string $theme;
    
    public function __construct(
        string $message,
        string $theme = '',
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
        $this->theme = $theme;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }
}

class AssetException extends TemplateException
{
    private string $asset;
    private string $source;
    private string $target;
    
    public function __construct(
        string $message,
        string $asset = '',
        string $source = '',
        string $target = '',
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
        $this->asset = $asset;
        $this->source = $source;
        $this->target = $target;
    }

    public function getAsset(): string
    {
        return $this->asset;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getTarget(): string
    {
        return $this->target;
    }
}

class ViewException extends TemplateException
{
    private string $view;
    
    public function __construct(
        string $message,
        string $view = '',
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $context, $code, $previous);
        $this->view = $view;
    }

    public function getView(): string
    {
        return $this->view;
    }
}

trait ExceptionHandler
{
    protected function handleException(\Throwable $e): void
    {
        if ($e instanceof SecurityException) {
            $this->handleSecurityException($e);
        } elseif ($e instanceof ValidationException) {
            $this->handleValidationException($e);
        } elseif ($e instanceof CompilationException) {
            $this->handleCompilationException($e);
        } else {
            $this->handleGenericException($e);
        }
    }

    private function handleSecurityException(SecurityException $e): void
    {
        // Log security violation
        $this->logger->critical('Security violation in template system', [
            'message' => $e->getMessage(),
            'resource' => $e->getResource(),
            'context' => $e->getContext(),
            'trace' => $e->getTraceAsString()
        ]);

        // Notify security team
        $this->notifySecurityTeam($e);

        throw $e;
    }

    private function handleValidationException(ValidationException $e): void
    {
        // Log validation error
        $this->logger->error('Validation error in template system', [
            'message' => $e->getMessage(),
            'errors' => $e->getErrors(),
            'context' => $e->getContext()
        ]);

        throw $e;
    }

    private function handleCompilationException(CompilationException $e): void
    {
        // Log compilation error
        $this->logger->error('Template compilation error', [
            'message' => $e->getMessage(),
            'template' => $e->getTemplate(),
            'line' => $e->getLine(),
            'context' => $e->getContext()
        ]);

        throw $e;
    }

    private function handleGenericException(\Throwable $e): void
    {
        // Log unexpected error
        $this->logger->error('Unexpected error in template system', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw $e;
    }
}
