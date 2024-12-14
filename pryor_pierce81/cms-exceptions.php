<?php
namespace App\Core\Exceptions;

abstract class CmsCriticalException extends \Exception {
    protected array $context = [];

    public function setContext(array $context): self {
        $this->context = $context;
        return $this;
    }

    public function getContext(): array {
        return $this->context;
    }
}

class SecurityException extends CmsCriticalException {}
class ValidationException extends CmsCriticalException {}
class ContentException extends CmsCriticalException {}
class PerformanceException extends CmsCriticalException {}
class ConfigurationException extends CmsCriticalException {}
class CacheException extends CmsCriticalException {}
class InvalidOperationException extends CmsCriticalException {}

class ExceptionHandler {
    private AuditLogger $logger;
    private AlertSystem $alerts;
    private SecurityManager $security;

    public function handle(\Exception $e): void {
        $this->logger->logException($e);
        
        if ($e instanceof SecurityException) {
            $this->security->handleSecurityException($e);
        }
        
        $this->alerts->notifyTeam($e);
        
        if ($this->shouldRethrow($e)) {
            throw $e;
        }
    }

    private function shouldRethrow(\Exception $e): bool {
        return $e instanceof CmsCriticalException;
    }
}
