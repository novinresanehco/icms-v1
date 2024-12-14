```php
namespace App\Core\Security;

final class SecurityManager
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $audit;
    private MonitoringService $monitor;

    public function executeProtected(callable $operation): mixed
    {
        $context = $this->createSecurityContext();
        
        try {
            $this->validateContext($context);
            $result = $operation();
            $this->validateResult($result);
            $this->audit->logSuccess($context);
            return $result;
        } catch (\Throwable $e) {
            $this->handleSecurityFailure($e, $context);
            throw new SecurityException('Security violation detected', 0, $e);
        }
    }

    private function validateContext(SecurityContext $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid security context');
        }

        if (!$this->validator->checkPermissions($context->getPermissions())) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Invalid operation result');
        }

        if ($result instanceof EncryptedData && !$this->encryption->verifyIntegrity($result)) {
            throw new SecurityException('Data integrity violation');
        }
    }

    private function handleSecurityFailure(\Throwable $e, SecurityContext $context): void
    {
        $this->audit->logFailure($e, $context);
        $this->monitor->recordSecurityIncident($e);

        if ($this->isSystemThreatening($e)) {
            $this->initiateEmergencyProtocol();
        }
    }

    private function initiateEmergencyProtocol(): void
    {
        $this->monitor->triggerEmergencyAlert();
        $this->audit->logEmergency();
        Cache::tags('security')->flush();
    }
}
```
