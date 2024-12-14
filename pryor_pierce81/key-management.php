<?php

namespace App\Core\Security\KeyManagement;

class KeyManagementService implements KeyManagementInterface
{
    private KeyStore $keyStore;
    private KeyGenerator $keyGenerator;
    private KeyRotator $keyRotator;
    private KeyValidator $keyValidator;
    private KeyLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        KeyStore $keyStore,
        KeyGenerator $keyGenerator,
        KeyRotator $keyRotator,
        KeyValidator $keyValidator,
        KeyLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->keyStore = $keyStore;
        $this->keyGenerator = $keyGenerator;
        $this->keyRotator = $keyRotator;
        $this->keyValidator = $keyValidator;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function generateKey(KeyGenerationContext $context): CryptoKey
    {
        $operationId = $this->initializeOperation($context);
        
        try {
            DB::beginTransaction();

            $this->validateContext($context);
            $key = $this->keyGenerator->generateKey($context);
            
            $this->validateNewKey($key);
            $this->storeKey($key);

            $this->logger->logKeyGeneration($key, $operationId);
            
            DB::commit();
            return $key;

        } catch (KeyGenerationException $e) {
            DB::rollBack();
            $this->handleGenerationFailure($e, $operationId);
            throw new CriticalKeyException($e->getMessage(), $e);
        }
    }

    public function rotateKey(KeyRotationRequest $request): RotationResult
    {
        $rotationId = $this->initializeRotation($request);
        
        try {
            DB::beginTransaction();

            $oldKey = $this->keyStore->getKey($request->getKeyId());
            $this->validateExistingKey($oldKey);

            $newKey = $this->keyRotator->rotateKey($oldKey, $request);
            $this->validateNewKey($newKey);

            $this->performKeyTransition($oldKey, $newKey);
            
            $result = new RotationResult([
                'rotationId' => $rotationId,
                'oldKeyId' => $oldKey->getId(),
                'newKeyId' => $newKey->getId(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (KeyRotationException $e) {
            DB::rollBack();
            $this->handleRotationFailure($e, $rotationId);
            throw new CriticalRotationException($e->getMessage(), $e);
        }
    }

    private function validateExistingKey(CryptoKey $key): void
    {
        if (!$this->keyValidator->validateKey($key)) {
            $this->emergency->initiateKeyCompromiseProtocol($key);
            throw new KeyValidationException('Existing key validation failed');
        }
    }

    private function performKeyTransition(CryptoKey $oldKey, CryptoKey $newKey): void
    {
        try {
            $this->keyStore->storeKey($newKey);
            $this->keyStore->markKeyForRetirement($oldKey);
            $this->logger->logKeyTransition($oldKey, $newKey);
        } catch (\Exception $e) {
            $this->emergency->initiateKeyRecovery($oldKey);
            throw new KeyTransitionException('Key transition failed', $e);
        }
    }

    private function handleRotationFailure(
        KeyRotationException $e,
        string $rotationId
    ): void {
        $this->logger->logRotationFailure($e, $rotationId);
        
        if ($e instanceof KeyCompromiseException) {
            $this->emergency->initiateEmergencyKeyRotation($e->getKey());
        }

        $this->emergency->lockdownKeyOperations();
    }
}
