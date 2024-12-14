<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface 
{
    private SecurityValidator $security;
    private BusinessValidator $business;
    private DataIntegrityValidator $integrity;

    public function validateContext(SecurityContext $context): void 
    {
        // Security context validation
        if (!$this->security->validateContext($context)) {
            throw new ValidationException('Invalid security context');
        }

        // Business rules validation
        if (!$this->business->validateContext($context)) {
            throw new ValidationException('Business rules validation failed');
        }

        // Data integrity validation
        if (!$this->integrity->validateContext($context)) {
            throw new ValidationException('Data integrity validation failed');
        }
    }

    public function verifyPermissions(array $permissions): void 
    {
        foreach ($permissions as $permission) {
            if (!$this->security->verifyPermission($permission)) {
                throw new SecurityException("Invalid permission: $permission");
            }
        }
    }

    public function validateResult(Result $result): void 
    {
        // Result structure validation
        if (!$this->integrity->validateResultStructure($result)) {
            throw new ValidationException('Invalid result structure');
        }

        // Business logic validation
        if (!$this->business->validateResult($result)) {
            throw new ValidationException('Business validation failed');
        }

        // Security validation
        if (!$this->security->validateResult($result)) {
            throw new SecurityException('Security validation failed');
        }
    }

    public function validateData(array $data, array $rules): array 
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException(
                'Data validation failed: ' . json_encode($validator->errors())
            );
        }

        return $validator->validated();
    }
}

class EncryptionService 
{
    private string $key;
    private string $algorithm = 'aes-256-gcm';

    public function encrypt(string $data): string 
    {
        $iv = random_bytes(16);
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $this->algorithm,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $encrypted);
    }

    public function decrypt(string $data): string 
    {
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $tag = substr($data, 16, 16);
        $encrypted = substr($data, 32);

        return openssl_decrypt(
            $encrypted,
            $this->algorithm,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
    }

    public function verifyIntegrity(Result $result): bool 
    {
        $hash = hash_hmac('sha256', serialize($result->getData()), $this->key);
        return hash_equals($hash, $result->getIntegrityHash());
    }
}
