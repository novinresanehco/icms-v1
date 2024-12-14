<?php

namespace App\Core\Exception;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Audit\AuditManagerInterface;
use Psr\Log\LoggerInterface;

abstract class CoreException extends \Exception
{
    protected array $context;
    protected int $severity;
    protected bool $isRecoverable;

    public function __construct(
        string $message,
        int $code = 0,
        \Throwable $previous = null,
        array $context = [],
        int $severity = self::SEVERITY_ERROR
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
        $this->severity = $severity;
        $this->isRecoverable = $this->determineRecoverability();
    }

    abstract protected function determineRecoverability(): bool;
    abstract public function getErrorType(): string;
    abstract public function getSecurityImpact(): int;
}

class SecurityException extends CoreException
{
    public const SEVERITY_CRITICAL = 1;
    private SecurityManagerInterface $security;
    private AuditManagerInterface $audit;
    private LoggerInterface $logger;

    public function __construct(
        string $message,
        SecurityManagerInterface $security,
        AuditManagerInterface $audit,
        LoggerInterface $logger,
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous, [], self::SEVERITY_CRITICAL);
        $this->security = $security;
        $this->audit = $audit;
        $this->logger = $logger;
        $this->handleSecurityException();
    }

    protected function determineRecoverability(): bool
    {
        return false;
    }

    public function getErrorType(): string
    {
        return 'SECURITY_VIOLATION';
    }

    public function getSecurityImpact(): int
    {
        return self::SEVERITY_CRITICAL;
    }

    private function handleSecurityException(): void
    {
        $this->security->lockdownSystem();
        $this->audit->logSecurityBreach($this);
        $this->logger->critical('Security exception occurred', [
            'message' => $this->getMessage(),
            'trace' => $this->getTraceAsString(),
            'context' => $this->context
        ]);
        $this->notifySecurityTeam();
    }

    private function notifySecurityTeam(): void
    {
        // Implementation for immediate security team notification
    }
}

class ValidationException extends CoreException
{
    private array $validationErrors;

    public function __construct(
        string $message,
        array $validationErrors = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->validationErrors = $validationErrors;
    }

    protected function determineRecoverability(): bool
    {
        return true;
    }

    public function getErrorType(): string
    {
        return 'VALIDATION_ERROR';
    }

    public function getSecurityImpact(): int
    {
        return self::SEVERITY_ERROR;
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
}

class SystemException extends CoreException
{
    private string $componentId;
    private array $systemState;

    public function __construct(
        string $message,
        string $componentId,
        array $systemState = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->componentId = $componentId;
        $this->systemState = $systemState;
    }

    protected function determineRecoverability(): bool
    {
        return $this->analyzeSystemState();
    }

    public function getErrorType(): string
    {
        return 'SYSTEM_ERROR';
    }

    public function getSecurityImpact(): int
    {
        return $this->calculateImpact();
    }

    private function analyzeSystemState(): bool
    {
        // Implementation for system state analysis
        return false;
    }

    private function calculateImpact(): int
    {
        // Implementation for impact calculation
        return self::SEVERITY_ERROR;
    }
}
