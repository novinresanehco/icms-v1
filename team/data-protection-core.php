namespace App\Core\Security;

class DataProtectionManager implements DataProtectionInterface 
{
    private EncryptionService $encryption;
    private KeyManager $keyManager;
    private DataValidator $validator;
    private AuditLogger $audit;
    private SecurityConfig $config;

    public function __construct(
        EncryptionService $encryption,
        KeyManager $keyManager,
        DataValidator $validator,
        AuditLogger $audit,
        SecurityConfig $config
    ) {
        $this->encryption = $encryption;
        $this->keyManager = $keyManager;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function protectData(SensitiveData $data, SecurityContext $context): ProtectedData
    {
        DB::beginTransaction();
        
        try {
            // Validate data before protection
            $this->validateData($data);
            
            // Generate encryption key
            $key = $this->keyManager->generateKey();
            
            // Encrypt data
            $encrypted = $this->encryptData($data, $key);
            
            // Verify encryption
            $this->verifyEncryption($encrypted, $data, $key);
            
            // Log operation
            $this->logProtection($data, $context);
            
            DB::commit();
            return new ProtectedData($encrypted, $key->getId());
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleProtectionFailure($e, $context);
            throw $e;
        }
    }

    public function accessProtectedData(ProtectedData $data, SecurityContext $context): SensitiveData
    {
        try {
            // Verify access authorization
            $this->verifyAccess($context);
            
            // Retrieve encryption key
            $key = $this->keyManager->getKey($data->getKeyId());
            
            // Decrypt data
            $decrypted = $this->decryptData($data, $key);
            
            // Verify decryption
            $this->verifyDecryption($decrypted);
            
            // Log access
            $this->logAccess($data, $context);
            
            return new SensitiveData($decrypted);
            
        } catch (\Exception $e) {
            $this->handleAccessFailure($e, $context);
            throw $e;
        }
    }

    protected function validateData(SensitiveData $data): void
    {
        if (!$this->validator->validateSensitiveData($data)) {
            throw new DataValidationException('Invalid sensitive data format');
        }
    }

    protected function encryptData(SensitiveData $data, EncryptionKey $key): string
    {
        return $this->encryption->encrypt(
            $data->toJson(),
            $key,
            $this->getEncryptionOptions()
        );
    }

    protected function decryptData(ProtectedData $data, EncryptionKey $key): string
    {
        return $this->encryption->decrypt(
            $data->getEncrypted(),
            $key,
            $this->getEncryptionOptions()
        );
    }

    protected function verifyEncryption(string $encrypted, SensitiveData $original, EncryptionKey $key): void
    {
        $decrypted = $this->encryption->decrypt($encrypted, $key);
        
        if (!hash_equals($original->hash(), hash('sha256', $decrypted))) {
            throw new EncryptionVerificationException('Encryption verification failed');
        }
    }

    protected function verifyDecryption(string $decrypted): void
    {
        if (!$this->validator->validateDecryptedData($decrypted)) {
            throw new DecryptionVerificationException('Decryption verification failed');
        }
    }

    protected function verifyAccess(SecurityContext $context): void
    {
        if (!$this->hasAccessPermission($context)) {
            throw new UnauthorizedAccessException('Access denied to protected data');
        }
    }

    protected function getEncryptionOptions(): array
    {
        return [
            'algorithm' => 'aes-256-gcm',
            'key_rotation' => $this->config->keyRotationInterval,
            'padding' => OPENSSL_PKCS7_PADDING
        ];
    }

    protected function logProtection(SensitiveData $data, SecurityContext $context): void
    {
        $this->audit->logDataProtection([
            'data_type' => $data->getType(),
            'protection_level' => $data->getProtectionLevel(),
            'context' => $context->toArray(),
            'timestamp' => now()
        ]);
    }

    protected function logAccess(ProtectedData $data, SecurityContext $context): void
    {
        $this->audit->logDataAccess([
            'data_id' => $data->getId(),
            'access_type' => 'decrypt',
            'context' => $context->toArray(),
            'timestamp' => now()
        ]);
    }

    protected function handleProtectionFailure(\Exception $e, SecurityContext $context): void
    {
        $this->audit->logProtectionFailure([
            'error' => $e->getMessage(),
            'context' => $context->toArray(),
            'timestamp' => now()
        ]);
    }

    protected function handleAccessFailure(\Exception $e, SecurityContext $context): void
    {
        $this->audit->logAccessFailure([
            'error' => $e->getMessage(),
            'context' => $context->toArray(),
            'timestamp' => now()
        ]);
    }

    protected function hasAccessPermission(SecurityContext $context): bool
    {
        return $context->hasPermission('access_protected_data') &&
               $this->validateAccessRequest($context);
    }

    protected function validateAccessRequest(SecurityContext $context): bool
    {
        return !$this->isUnusualAccess($context) &&
               !$this->hasRecentViolations($context) &&
               $this->isWithinRateLimit($context);
    }
}
