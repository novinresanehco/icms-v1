<?php

namespace App\Core\Template\Protection;

class TemplateBoundaryGuard
{
    private SecurityManager $security;
    private ErrorHandler $errorHandler;
    private array $boundaries = [];

    public function __construct(SecurityManager $security, ErrorHandler $errorHandler)
    {
        $this->security = $security;
        $this->errorHandler = $errorHandler;
    }

    public function enforceOperation(string $operation, array $context): mixed
    {
        return DB::transaction(function() use ($operation, $context) {
            try {
                $this->validateBoundary($operation);
                $this->security->validateContext($context);
                
                return $this->executeOperation($operation, $context);
            } catch (BoundaryViolationException $e) {
                $this->errorHandler->handleViolation($e, $context);
                throw $e;
            } catch (\Throwable $e) {
                $this->errorHandler->handleError($e, $context);
                throw new TemplateCriticalException($e->getMessage(), 0, $e);
            }
        });
    }

    private function validateBoundary(string $operation): void
    {
        if (!in_array($operation, [
            'template_render',
            'component_render',
            'media_process',
            'content_display'
        ])) {
            throw new BoundaryViolationException("Operation not allowed: {$operation}");
        }
    }

    private function executeOperation(string $operation, array $context): mixed
    {
        return match($operation) {
            'template_render' => $this->handleTemplateRender($context),
            'component_render' => $this->handleComponentRender($context),
            'media_process' => $this->handleMediaProcess($context),
            'content_display' => $this->handleContentDisplay($context)
        };
    }

    private function handleTemplateRender(array $context): string
    {
        $this->security->validateTemplateAccess($context);
        return (new TemplateRenderer($this->security))->render($context);
    }

    private function handleComponentRender(array $context): string
    {
        $this->security->validateComponentAccess($context);
        return (new ComponentRenderer($this->security))->render($context);
    }

    private function handleMediaProcess(array $context): string
    {
        $this->security->validateMediaAccess($context);
        return (new MediaProcessor($this->security))->process($context);
    }

    private function handleContentDisplay(array $context): string
    {
        $this->security->validateContentAccess($context);
        return (new ContentDisplay($this->security))->display($context);
    }
}

class ErrorHandler
{
    private LogManager $logger;

    public function handleViolation(BoundaryViolationException $e, array $context): void
    {
        $this->logger->critical('Boundary violation', [
            'exception' => $e,
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }

    public function handleError(\Throwable $e, array $context): void
    {
        $this->logger->error('Template system error', [
            'exception' => $e,
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class BoundaryViolationException extends \Exception {}
class TemplateCriticalException extends \Exception {}

interface BoundaryInterface
{
    public function enforceOperation(string $operation, array $context): mixed;
}
