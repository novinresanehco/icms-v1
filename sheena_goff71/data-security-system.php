<?php

namespace App\Core\Security;

class DataSecuritySystem implements DataSecurityInterface 
{
    private EncryptionService $encryption;
    private ValidationEngine $validator;
    private IntegrityChecker $integrity;
    private SecurityMonitor $monitor;
    private EmergencyHandler $emergency;

    public function __construct(
        EncryptionService $encryption,
        ValidationEngine $validator,
        IntegrityChecker $integrity,
        SecurityMonitor $monitor,
        EmergencyHandler $emergency
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->integrity = $integrity;
        $this->monitor = $monitor;
        $this->emergency = $emergency;
    }

    public function processSecureData(DataPackage $data): SecureDataResult 
    {
        $operationId = $this->monitor->startOperation();
        DB::beginTransaction();

        try {
            $validationResult = $this->validator->validate($data);
            if (!$validationResult->isValid()) {
                throw new DataValidationException('Critical data validation failed');
            }

            $encryptedData = $this->encryption->encrypt(
                $data->getContent(),
                $this->generateEncryptionContext($data)
            );

            $integrityHash = $this->integrity->generateHash($encryptedData);
            
            $this->monitor->recordSuccess($operationId);
            DB::commit();

            return new SecureDataResult(
                data: $encryptedData,
                hash: $integrityHash,
                metadata: $this->generateSecurityMetadata($data)
            );

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($operationId, $data, $e);
            throw new DataSecurityException(
                'Critical data security operation failed',
                previous: $e
            );
        }
    }

    private function generateEncryptionContext(DataPackage $data): EncryptionContext 
    {
        return new EncryptionContext([
            'algorithm' => 'AES-256-GCM',
            'key_id' => $this->encryption->getCurrentKeyId(),
            'timestamp' => now()->timestamp,
            'security_level' => $data->getSecurityLevel(),
            'operation_id' => Str::uuid()
        ]);
    }

    private function generateSecurityMetadata(DataPackage $data): SecurityMetadata 
    {
        return new SecurityMetadata([
            'encryption_method' => $this->encryption->getMethod(),
            'integrity_algorithm' => $this->integrity->getAlgorithm(),
            'validation_rules' => $this->validator->getRules(),
            'security_version' => config('security.version'),
            'timestamp' => now()
        ]);
    }

    private function handleSecurityFailure(
        string $operationId,
        DataPackage $data,
        \Exception $e
    ): void {
        $this->monitor->recordFailure($operationId, [
            'error' => $e->getMessage(),
            'data_id' => $data->getId(),
            'security_level' => $data->getSecurityLevel(),
            'timestamp' => now()
        ]);

        if ($this->isEmergencySituation($e)) {
            $this->emergency->handleCriticalFailure(
                $operationId,
                $data,
                $e
            );
        }
    }

    private function isEmergencySituation(\Exception $e): bool 
    {
        return $e instanceof CriticalSecurityException ||
               $e instanceof DataCorruptionException ||
               $e instanceof SystemCompromiseException;
    }
}

class EncryptionService 
{
    private KeyManager $keyManager;
    private CipherEngine $engine;
    private SecurityConfig $config;

    public function encrypt(string $data, EncryptionContext $context): EncryptedData 
    {
        $key = $this->keyManager->getKey($context->getKeyId());
        
        $encryptedContent = $this->engine->encrypt(
            $data,
            $key,
            $context->getAlgorithm()
        );
        
        return new EncryptedData(
            content: $encryptedContent,
            context: $context,
            metadata: $this->generateMetadata($key, $context)
        );
    }

    public function decrypt(EncryptedData $data): DecryptedData 
    {
        $key = $this->keyManager->getKey($data->getContext()->getKeyId());
        
        $decryptedContent = $this->engine->decrypt(
            $data->getContent(),
            $key,
            $data->getContext()->getAlgorithm()
        );
        
        return new DecryptedData(
            content: $decryptedContent,
            metadata: $data->getMetadata()
        );
    }

    private function generateMetadata(
        EncryptionKey $key,
        EncryptionContext $context
    ): EncryptionMetadata {
        return new EncryptionMetadata([
            'key_id' => $key->getId(),
            'algorithm' => $context->getAlgorithm(),
            'timestamp' => now(),
            'version' => $this->config->getVersion()
        ]);
    }
}

class ValidationEngine 
{
    private RuleEngine $rules;
    private DataValidator $validator;
    private IntegrityChecker $integrity;

    public function validate(DataPackage $data): ValidationResult 
    {
        $rules = $this->rules->getRulesForData($data);
        
        $validationResults = $this->validator->validateAgainstRules(
            $data,
            $rules
        );

        $integrityValid = $this->integrity->verify(
            $data->getContent(),
            $data->getHash()
        );

        if (!$validationResults->isValid() || !$integrityValid) {
            return new ValidationResult(false, array_merge(
                $validationResults->getErrors(),
                $integrityValid ? [] : ['Integrity check failed']
            ));
        }

        return new ValidationResult(true);
    }

    public function getRules(): array 
    {
        return $this->rules->getCurrentRules();
    }
}

class IntegrityChecker 
{
    private HashGenerator $hasher;
    private SecurityConfig $config;

    public function generateHash(EncryptedData $data): string 
    {
        return $this->hasher->generate(
            $data->getContent(),
            $this->config->getHashAlgorithm()
        );
    }

    public function verify(string $data, string $hash): bool 
    {
        $computedHash = $this->hasher->generate(
            $data,
            $this->config->getHashAlgorithm()
        );
        
        return hash_equals($computedHash, $hash);
    }

    public function getAlgorithm(): string 
    {
        return $this->config->getHashAlgorithm();
    }
}