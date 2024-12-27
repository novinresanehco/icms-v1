<?php

namespace App\Core\Security;

use App\Core\Interfaces\{SecurityManagerInterface, ValidationInterface, AuditInterface};
use Illuminate\Support\Facades\{DB, Hash, Log};

class SecurityManager implements SecurityManagerInterface
{
    protected ValidationService $validator;
    protected EncryptionService $encryption;
    protected AuditLogger $auditLogger;
    protected BackupService $backup;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        BackupService $backup
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->backup = $backup;
    }

    public function createBackupPoint(): string
    {
        try {
            $backupId = uniqid('backup_', true);
            $this->backup->create($backupId);
            $this->auditLogger->log('backup.created', ['backup_id' => $backupId]);
            return $backupId;
        } catch (\Exception $e) {
            Log::critical('Backup creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function removeBackupPoint(string $backupId): void
    {
        try {
            $this->backup->remove($backupId);
            $this->auditLogger->log('backup.removed', ['backup_id' => $backupId]);
        } catch (\Exception $e) {
            Log::error('Backup removal failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function generateChecksum(array $data): string
    {
        $serialized = json_encode($this->sortRecursive($data));
        return hash_hmac('sha256', $serialized, config('app.key'));
    }

    public function validateChecksum(array $data, string $checksum): bool
    {
        return hash_equals($checksum, $this->generateChecksum($data));
    }

    public function executeSecure(callable $operation, array $context = []): mixed
    {
        $backupId = $this->createBackupPoint();
        $startTime = microtime(true);

        try {
            DB::beginTransaction();

            $result = $operation();

            DB::commit();
            $this->removeBackupPoint($backupId);

            $this->auditLogger->log('operation.success', [
                'context' => $context,
                'duration' => microtime(true) - $startTime
            ]);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context, $backupId);
            throw $e;
        }
    }

    protected function handleFailure(\Exception $e, array $context, string $backupId): void
    {
        Log::critical('Secure operation failed', [
            'error' => $e->getMessage(),
            'context' => $context,
            'backup_id' => $backupId,
            'trace' => $e->getTraceAsString()
        ]);

        $this->auditLogger->log('operation.failed', [
            'error' => $e->getMessage(),
            'context' => $context
        ]);

        try {
            $this->backup->restore($backupId);
            $this->removeBackupPoint($backupId);
        } catch (\Exception $restoreException) {
            Log::critical('Backup restoration failed', [
                'backup_id' => $backupId,
                'error' => $restoreException->getMessage()
            ]);
        }
    }

    protected function sortRecursive(array $array): array
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $value = $this->sortRecursive($value);
            }
        }
        ksort($array);
        return $array;
    }
}

class ValidationService
{
    protected array $rules = [
        'permission' => [
            'name' => 'required|string|max:50',
            'display_name' => 'required|string|max:100',
            'module' => 'required|string|max:50'
        ],
        'role' => [
            'name' => 'required|string|max:50',
            'display_name' => 'required|string|max:100',
            'description' => 'required|string|max:255'
        ],
        'user' => [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8'
        ]
    ];

    public function validatePermission(array $data): array
    {
        return $this->validate($data, $this->rules['permission']);
    }

    public function validateRole(array $data): array
    {
        return $this->validate($data, $this->rules['role']);
    }

    public function validateUser(array $data): array
    {
        return $this->validate($data, $this->rules['user']);
    }

    public function validatePermissionAssignment($role, $permissions): array
    {
        // Validate permission assignment logic
        return $permissions->filter(function($permission) use ($role) {
            return $this->canAssignPermission($role, $permission);
        })->values()->all();
    }

    public function validateRoleAssignment($user, $roles): array
    {
        // Validate role assignment logic
        return $roles->filter(function($role) use ($user) {
            return $this->canAssignRole($user, $role);
        })->values()->all();
    }

    protected function validate(array $data, array $rules): array
    {
        $validator = validator($data, $rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }

        return $validator->validated();
    }

    protected function canAssignPermission($role, $permission): bool
    {
        // Implementation of permission assignment rules
        return true;
    }

    protected function canAssignRole($user, $role): bool
    {
        // Implementation of role assignment rules
        return true;
    }
}

class EncryptionService
{
    public function encrypt(string $data): string
    {
        $key = config('app.key');
        $cipher = config('app.cipher', 'AES-256-CBC');
        $iv = random_bytes(openssl_cipher_iv_length($cipher));

        $encrypted = openssl_encrypt(
            $data,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $data): string
    {
        $key = config('app.key');
        $cipher = config('app.cipher', 'AES-256-CBC');
        
        $data = base64_decode($data);
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        return openssl_decrypt(
            $encrypted,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
    }
}