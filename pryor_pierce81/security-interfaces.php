<?php

namespace App\Interfaces;

interface SecurityServiceInterface {
    public function validateSecureOperation(callable $operation, array $context = []): mixed;
}

interface EncryptionServiceInterface {
    public function encrypt(string $data): string;
    public function decrypt(string $encrypted): string;
    public function isEncrypted(string $data): bool;
    public function generateKey(): string;
    public function rotateKeys(): void;
}

interface AuditServiceInterface {
    public function startOperation(array $context): string;
    public function completeOperation(string $auditId, $result): void;
    public function logFailure(\Throwable $e, array $context): void;
    public function logUnauthorizedAccess(array $context): void;
}

namespace App\Services;

class EncryptionService implements EncryptionServiceInterface {
    private string $cipher = 'AES-256-CBC';
    private string $activeKey;

    public function __construct() {
        $this->activeKey = config('app.encryption_key');
    }

    public function encrypt(string $data): string {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        $encrypted = openssl_encrypt($data, $this->cipher, $this->activeKey, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $encrypted): string {
        $decoded = base64_decode($encrypted);
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($decoded, 0, $ivLength);
        $encrypted = substr($decoded, $ivLength);
        return openssl_decrypt($encrypted, $this->cipher, $this->activeKey, 0, $iv);
    }

    public function isEncrypted(string $data): bool {
        try {
            $decoded = base64_decode($data, true);
            return $decoded !== false && strlen($decoded) > 16;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function generateKey(): string {
        return base64_encode(random_bytes(32));
    }

    public function rotateKeys(): void {
        $newKey = $this->generateKey();
        // Implement key rotation logic
        $this->activeKey = $newKey;
    }
}

class AuditService implements AuditServiceInterface {
    public function startOperation(array $context): string {
        $auditId = (string) Str::uuid();
        
        DB::table('audit_log')->insert([
            'audit_id' => $auditId,
            'user_id' => $context['user_id'] ?? null,
            'action' => $context['action'] ?? 'unknown',
            'ip_address' => request()->ip(),
            'started_at' => now(),
            'context' => json_encode($context)
        ]);

        return $auditId;
    }

    public function completeOperation(string $auditId, $result): void {
        DB::table('audit_log')
            ->where('audit_id', $auditId)
            ->update([
                'completed_at' => now(),
                'status' => 'completed',
                'result' => json_encode($result)
            ]);
    }

    public function logFailure(\Throwable $e, array $context): void {
        DB::table('audit_log')->insert([
            'audit_id' => (string) Str::uuid(),
            'user_id' => $context['user_id'] ?? null,
            'action' => 'error',
            'ip_address' => request()->ip(),
            'started_at' => now(),
            'completed_at' => now(),
            'status' => 'failed',
            'context' => json_encode([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'context' => $context
            ])
        ]);
    }

    public function logUnauthorizedAccess(array $context): void {
        DB::table('audit_log')->insert([
            'audit_id' => (string) Str::uuid(),
            'user_id' => $context['user_id'] ?? null,
            'action' => 'unauthorized_access',
            'ip_address' => request()->ip(),
            'started_at' => now(),
            'completed_at' => now(),
            'status' => 'blocked',
            'context' => json_encode($context)
        ]);
    }
}
