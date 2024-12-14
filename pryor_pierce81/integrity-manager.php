<?php

namespace App\Core\Integrity;

class IntegrityManager implements IntegrityInterface
{
    private HashVerifier $hashVerifier;
    private SignatureValidator $signatureValidator;
    private CryptoService $cryptoService;
    private StateValidator $stateValidator;
    private IntegrityLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        HashVerifier $hashVerifier,
        SignatureValidator $signatureValidator,
        CryptoService $cryptoService,
        StateValidator $stateValidator,
        IntegrityLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->hashVerifier = $hashVerifier;
        $this->signatureValidator = $signatureValidator;
        $this->cryptoService = $cryptoService;
        $this->stateValidator = $stateValidator;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function verifySystemIntegrity(SystemContext $context): IntegrityResult
    {
        $verificationId = $this->initializeVerification($context);
        
        try {
            DB::beginTransaction();

            $this->verifyStateIntegrity($context);
            $this->verifySignatures($context);
            $this->verifyHashes($context);
            $this->validateSystemState($context);

            $result = new IntegrityResult([
                'verificationId' => $verificationId,
                'status' => IntegrityStatus::VERIFIED,
                'metrics' => $this->collectMetrics($context),
                'timestamp' => now()
            ]);

            DB::commit();
            $this->finalizeVerification($result);

            return $result;

        } catch (IntegrityException $e) {
            DB::rollBack();
            $this->handleIntegrityFailure($e, $verificationId);
            throw new CriticalIntegrityException($e->getMessage(), $e);
        }
    }

    private function verifyStateIntegrity(SystemContext $context): void
    {
        if (!$this->stateValidator->validateState($context->getState())) {
            $this->emergency->initiateProtocol(EmergencyLevel::CRITICAL);
            throw new StateIntegrityException('System state integrity compromised');
        }
    }

    private function verifySignatures(SystemContext $context): void
    {
        $signatureErrors = $this->signatureValidator->verifyAll($context->getSignatures());
        
        if (!empty($signatureErrors)) {
            throw new SignatureVerificationException(
                'Signature verification failed',
                ['errors' => $signatureErrors]
            );
        }
    }

    private function handleIntegrityFailure(IntegrityException $e, string $verificationId): void
    {
        $this->logger->logCriticalFailure($e, $verificationId);
        $this->emergency->initiateProtocol(EmergencyLevel::CRITICAL);
        
        $this->terminateCompromisedOperations($verificationId);
    }

    private function terminateCompromisedOperations(string $verificationId): void
    {
        try {
            $this->emergency->lockdownSystem();
            $this->cryptoService->revokeCompromisedKeys();
            $this->logger->logEmergencyAction('System lockdown initiated', $verificationId);
        } catch (\Exception $e) {
            $this->logger->logEmergencyFailure($e, $verificationId);
            throw new CatastrophicFailureException('Failed to secure compromised system');
        }
    }
}
