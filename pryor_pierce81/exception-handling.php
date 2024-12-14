<?php
namespace App\Core\Exceptions;

abstract class CriticalCmsException extends \Exception {
    protected array $context = [];
    protected string $severity = 'critical';
    protected bool $reportable = true;

    public function setContext(array $context): self {
        $this->context = $context;
        return $this;
    }

    public function getContext(): array {
        return $this->context;
    }

    public function getSeverity(): string {
        return $this->severity;
    }

    public function isReportable(): bool {
        return $this->reportable;
    }
}

class SecurityException extends CriticalCmsException {}
class ValidationException extends CriticalCmsException {}
class ContentException extends CriticalCmsException {}
class CacheException extends CriticalCmsException {}
class DatabaseException extends CriticalCmsException {}
class InvalidOperationException extends CriticalCmsException {}

class ExceptionHandler {
    private SecurityManager $security;
    private AuditLogger $logger;
    private AlertSystem $alerts;

    public function handle(\Exception $e): void {
        // Log all exceptions
        $this->logger->logException($e);
        
        if ($e instanceof SecurityException) {
            $this->handleSecurityException($e);
        }
        
        if ($e instanceof CriticalCmsException && $e->isReportable()) {
            $this->alerts->notifyTeam($e);
        }
        
        throw $e;
    }

    private function handleSecurityException(SecurityException $e): void {
        $this->security->handleSecurityIncident($e);
        $this->alerts->notifySecurityTeam($e);
    }
}

interface SecurityManager {
    public function handleSecurityIncident(SecurityException $e): void;
}

interface AuditLogger {
    public function logException(\Exception $e): void;
}

interface AlertSystem {
    public function notifyTeam(\Exception $e): void;
    public function notifySecurityTeam(SecurityException $e): void;
}
