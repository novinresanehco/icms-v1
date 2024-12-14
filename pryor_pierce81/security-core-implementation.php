<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityInterface;
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityInterface 
{
    private const MAX_ATTEMPTS = 3;
    private const SESSION_TIMEOUT = 900; // 15 minutes

    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $audit;
    
    public function executeSecureOperation(callable $operation): mixed 
    {
        DB::beginTransaction();
        
        try {
            $this->validateSecurityContext();
            $result = $operation();
            $this->validateResult($result);
            
            DB::commit();
            $this->audit->logSuccess();
            
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->audit->logFailure($e);
            throw $e;
        }
    }

    public function validateRequest(Request $request): void 
    {
        if (!$this->validator->validateInput($request->all())) {
            throw new SecurityException('Invalid input');
        }

        if ($this->exceedsRateLimit($request)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    public function encryptData(array $data): array 
    {
        return array_map(
            fn($value) => $this->encryption->encrypt($value),
            $data
        );
    }

    private function validateSecurityContext(): void 
    {
        if (!$this->validator->checkCurrentContext()) {
            throw new SecurityException('Invalid security context');
        }
    }
}

class ValidationService 
{
    public function validateInput(array $data): bool 
    {
        foreach ($data as $key => $value) {
            if (!$this->sanitizeInput($value)) {
                $this->audit->logValidationFailure($key);
                return false;
            }
        }
        return true;
    }

    private function sanitizeInput($input): bool 
    {
        return filter_var(
            $input,
            FILTER_SANITIZE_STRING,
            FILTER_FLAG_STRIP_HIGH
        ) !== false;
    }
}

class EncryptionService 
{
    private string $key;
    private string $cipher = 'AES-256-CBC';

    public function encrypt(string $value): string 
    {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        return base64_encode(
            openssl_encrypt(
                $value,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            ) . '::' . $iv
        );
    }
}

class AuditLogger 
{
    public function logSuccess(): void 
    {
        $this->log('Operation completed successfully', 'success');
    }

    public function logFailure(\Exception $e): void 
    {
        $this->log(
            'Operation failed: ' . $e->getMessage(),
            'failure',
            [
                'trace' => $e->getTraceAsString(),
                'code' => $e->getCode()
            ]
        );
    }

    private function log(string $message, string $type, array $context = []): void 
    {
        DB::table('security_logs')->insert([
            'message' => $message,
            'type' => $type,
            'context' => json_encode($context),
            'created_at' => now()
        ]);
    }
}
