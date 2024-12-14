<?php

namespace App\Core\Security\Encryption;

class EncryptionService implements EncryptionInterface
{
    private KeyManager $keyManager;
    private AlgorithmSelector $algorithmSelector;
    private CipherManager $cipherManager;
    private IntegrityVerifier $integrityVerifier;
    private EncryptionLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        KeyManager $keyManager,
        AlgorithmSelector $algorithmSelector,
        CipherManager $cipherManager,
        IntegrityVerifier $integrityVerifier,
        EncryptionLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->keyManager = $keyManager;
        $this->algorithmSelector = $algorithmSelector;
        $this->cipherManager = $cipherManager;
        $this->integrityVerifier = $integrityVerifier;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function encrypt(EncryptionRequest $request): EncryptionResult
    {
        $operationId = $this->initializeOperation($request);
        
        try {
            DB::beginTransaction();

            $algorithm = $this->algorithmSelector->selectAlgorithm($request);
            $key = $this->keyManager->getEncryptionKey($request);
            
            $this->validateKey($key);
            $this->validateAlgorithm($algorithm);

            $cipher = $this->cipherManager->createCipher($algorithm, $key);
            $encryptedData = $cipher->encrypt($request->getData());
            
            $this->verifyEncryption($encryptedData, $request->getData(), $key);

            $result = new EncryptionResult([
                'operationId' => $operationId,
                'encryptedData' => $encryptedData,
                'algorithm' => $algorithm->getIdentifier(),
                'keyId' => $key->getId(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (EncryptionException $e) {
            DB::rollBack();
            $this->handleEncryptionFailure($e, $operationId);
            throw new CriticalEncryptionException($e->getMessage(), $e);
        }
    }

    private function validateKey(EncryptionKey $key): void
    {
        if (!$this->keyManager->isKeyValid($key)) {
            $this->emergency->initiateKeyCompromiseProtocol($key);
            throw new KeyValidationException('Encryption key validation failed');
        }
    }

    private function verifyEncryption(
        string $encryptedData,
        string $originalData,
        EncryptionKey $key
    ): void {
        if (!$this->integrityVerifier->verifyEncryption(
            $encryptedData,
            $originalData,
            $key
        )) {
            throw new EncryptionVerificationException('Encryption verification failed');
        }
    }

    private function handleEncryptionFailure(
        EncryptionException $e,
        string $operationId
    ): void {
        $this->logger->logCriticalFailure($e, $operationId);
        
        if ($e instanceof KeyCompromiseException) {
            $this->emergency->initiateKeyRevocation($e->getKey());
        }

        $this->emergency->lockdownEncryptionOperations();
    }
}
