<?php

namespace App\Core\Integrity;

class DataIntegrityService implements IntegrityInterface
{
    private HashValidator $hashValidator;
    private SignatureVerifier $signatureVerifier;
    private ChecksumManager $checksumManager;
    private IntegrityLogger $logger;
    private EmergencyProtocol $emergency;
    private QuarantineService $quarantine;

    public function __construct(
        HashValidator $hashValidator,
        SignatureVerifier $signatureVerifier,
        ChecksumManager $checksumManager,
        IntegrityLogger $logger,
        EmergencyProtocol $emergency,
        QuarantineService $quarantine
    ) {
        $this->hashValidator = $hashValidator;
        $this->signatureVerifier = $signatureVerifier;
        $this->checksumManager = $checksumManager;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->quarantine = $quarantine;
    }

    public function verifyIntegrity(IntegrityContext $context): IntegrityResult
    {
        $verificationId = $this->initializeVerification($context);
        
        try {
            DB::beginTransaction();

            $hashValidation = $this->validateHashes($context);
            $signatureValidation = $this->verifySignatures($context);
            $checksumValidation = $this->validateChecksums($context);

            if (!$this->isIntegrityMaintained($hashValidation, $signatureValidation, $checksumValidation)) {
                $this->handleIntegrityBreach([
                    'hashes' => $hashValidation,
                    'signatures' => $signatureValidation,
                    'checksums' => $checksumValidation
                ]);
            }

            $result = new IntegrityResult([
                'verificationId' => $verificationId,
                'hashValidation' => $hashValidation,
                'signatureValidation' => $signatureValidation,
                'checksumValidation' => $checksumValidation,
                'status' => IntegrityStatus::VERIFIED,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (IntegrityException $e) {
            DB::rollBack();
            $this->handleIntegrityFailure($e, $verificationId);
            throw new CriticalIntegrityException($e->getMessage(), $e);
        }
    }

    private function handleIntegrityBreach(array $validationResults): void
    {
        $this->logger->logIntegrityBreach($validationResults);
        $this->emergency->handleIntegrityBreach($validationResults);
        
        // Quarantine compromised data
        $compromisedData = $this->identifyCompromisedData($validationResults);
        $this->quarantine->isolateData($compromisedData, QuarantineLevel::CRITICAL);
        
        throw new IntegrityBreachException('Data integrity breach detected');
    }

    private function identifyCompromisedData(array $validationResults): array
    {
        return array_filter($validationResults, function($result) {
            return $result->isCompromised();
        });
    }
}

