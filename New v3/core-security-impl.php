<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Exceptions\{SecurityException, ValidationException};

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    public function validateRequest(Request $request): SecurityContext
    {
        DB::beginTransaction();
        try {
            // Pre-validation logging
            $this->auditLogger->logAccess($request);

            // Validate request integrity
            $validatedData = $this->validator->validateRequest($request);

            // Check permissions
            $this->accessControl->validateAccess($request);

            // Create security context
            $context = new SecurityContext($validatedData);
            
            DB::commit();
            return $context;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $request);
            throw $e;
        }
    }

    public function encryptSensitiveData(array $data): array 
    {
        try {
            $encryptedData = [];
            foreach ($data as $key => $value) {
                if ($this->isSensitive($key)) {
                    $encryptedData[$key] = $this->encryption->encrypt($value);
                } else {
                    $encryptedData[$key] = $value;
                }
            }
            return $encryptedData;
        } catch (\Exception $e) {
            $this->auditLogger->logSecurityFailure('encryption_failed', $e);
            throw new SecurityException('Failed to encrypt sensitive data', 0, $e);
        }
    }

    private function handleSecurityFailure(\Exception $e, Request $request): void 
    {
        $this->auditLogger->logSecurityEvent([
            'type' => 'security_failure',
            'error' => $e->getMessage(),
            'request' => [
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
                'method' => $request->method()
            ]
        ]);
    }

    private function isSensitive(string $key): bool
    {
        return in_array($key, [
            'password',
            'token',
            'api_key',
            'credit_card',
            'ssn'
        ]);
    }
}

class ValidationService
{
    private array $rules = [];

    public function validateRequest(Request $request): array
    {
        $data = $request->all();
        
        foreach ($this->rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException("Validation failed for {$field}");
            }
        }

        return $data;
    }

    private function validateField($value, string $rule): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'string' => is_string($value),
            'numeric' => is_numeric($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'array' => is_array($value),
            default => true
        };
    }
}

class AuditLogger
{
    public function logAccess(Request $request): void
    {
        DB::table('audit_logs')->insert([
            'action' => 'access',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now()
        ]);
    }

    public function logSecurityEvent(array $data): void
    {
        DB::table('security_events')->insert([
            'type' => $data['type'],
            'context' => json_encode($data),
            'created_at' => now()
        ]);
    }

    public function logSecurityFailure(string $type, \Exception $e): void
    {
        DB::table('security_events')->insert([
            'type' => $type,
            'context' => json_encode([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]),
            'created_at' => now()
        ]);
    }
}
