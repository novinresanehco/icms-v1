<?php
namespace App\Core\Security;

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $audit;
    private Monitor $monitor;

    public function executeCriticalOperation(callable $operation)
    {
        $context = $this->createSecurityContext();
        $this->monitor->startOperation($context);
        
        try {
            $result = $this->executeSecureOperation($operation, $context);
            $this->monitor->endOperation($context, true);
            return $result;
            
        } catch (\Exception $e) {
            $this->monitor->endOperation($context, false);
            $this->handleSecurityFailure($e, $context);
            throw $e;
        }
    }

    private function executeSecureOperation(callable $operation, SecurityContext $context)
    {
        $this->validateSecurityContext($context);
        $this->audit->logOperationStart($context);
        
        $result = $operation();
        
        $this->validateOperationResult($result);
        $this->audit->logOperationEnd($context);
        
        return $result;
    }

    private function validateSecurityContext(SecurityContext $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityContextException('Invalid security context');
        }
    }

    private function handleSecurityFailure(\Exception $e, SecurityContext $context): void
    {
        $this->audit->logSecurityFailure($e, $context);
        $this->monitor->reportSecurityIncident($e, $context);
    }
}

class ValidationService implements ValidationInterface
{
    private RuleEngine $rules;
    private SecurityConfig $config;

    public function validate($data, array $rules): bool
    {
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field], $rule)) {
                throw new ValidationException("Validation failed for field: $field");
            }
        }
        return true;
    }

    public function validateContext(SecurityContext $context): bool
    {
        return $this->rules->validateContext($context, $this->config->getContextRules());
    }

    private function validateField($value, $rule): bool
    {
        return $this->rules->validate($value, $rule);
    }
}

class EncryptionService implements EncryptionInterface
{
    private string $key;
    private string $cipher;

    public function encrypt(string $data): string
    {
        return openssl_encrypt($data, $this->cipher, $this->key);
    }

    public function decrypt(string $encrypted): string
    {
        return openssl_decrypt($encrypted, $this->cipher, $this->key);
    }

    public function hash(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key);
    }
}

class AuditLogger implements AuditInterface
{
    private LogManager $logger;
    
    public function logOperationStart(SecurityContext $context): void
    {
        $this->logger->info('Operation started', [
            'context' => $context->toArray(),
            'timestamp' => microtime(true)
        ]);
    }

    public function logOperationEnd(SecurityContext $context): void
    {
        $this->logger->info('Operation completed', [
            'context' => $context->toArray(),
            'timestamp' => microtime(true)
        ]);
    }

    public function logSecurityFailure(\Exception $e, SecurityContext $context): void
    {
        $this->logger->critical('Security failure', [
            'exception' => [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ],
            'context' => $context->toArray(),
            'timestamp' => microtime(true)
        ]);
    }
}

class Monitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private AlertService $alerts;

    public function startOperation(SecurityContext $context): void
    {
        $this->metrics->recordOperationStart($context);
    }

    public function endOperation(SecurityContext $context, bool $success): void
    {
        $this->metrics->recordOperationEnd($context, $success);
    }

    public function reportSecurityIncident(\Exception $e, SecurityContext $context): void
    {
        $this->alerts->reportSecurityIncident([
            'exception' => $e,
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
    }
}
