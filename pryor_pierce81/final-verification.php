<?php

namespace App\Core\Verification;

class FinalVerificationService implements FinalVerificationInterface
{
    private ComplianceValidator $complianceValidator;
    private ArchitectureValidator $architectureValidator;
    private SecurityValidator $securityValidator;
    private VerificationLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        ComplianceValidator $complianceValidator,
        ArchitectureValidator $architectureValidator,
        SecurityValidator $securityValidator,
        VerificationLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->complianceValidator = $complianceValidator;
        $this->architectureValidator = $architectureValidator;
        $this->securityValidator = $securityValidator;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function performFinalVerification(VerificationContext $context): VerificationResult
    {
        $verificationId = $this->initializeVerification($context);
        
        try {
            DB::beginTransaction();

            $this->verifyCompliance($context);
            $this->verifyArchitecture($context);
            $this->verifySecurity($context);

            $this->validateFinalState($context);

            $result = new VerificationResult([
                'verificationId' => $verificationId,
                'status' => VerificationStatus::VERIFIED,
                'metrics' => $this->collectFinalMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (VerificationException $e) {
            DB::rollBack();
            $this->handleVerificationFailure($e, $verificationId);
            throw new CriticalVerificationException($e->getMessage(), $e);
        }
    }

    private function validateFinalState(VerificationContext $context): void
    {
        $state = $this->getFinalSystemState($context);
        
        if (!$this->isStateSatisfactory($state)) {
            $this->emergency->handleInvalidFinalState($state);
            throw new InvalidFinalStateException('System final state validation failed');
        }
    }

    private function isStateSatisfactory(SystemState $state): bool
    {
        return $state->complianceVerified &&
               $state->architectureVerified &&
               $state->securityVerified &&
               $state->integrityMaintained;
    }

    private function handleVerificationFailure(
        VerificationException $e,
        string $verificationId
    ): void {
        $this->logger->logCriticalFailure($e, $verificationId);
        $this->emergency->initiateEmergencyProtocol();
        $this->alerts->dispatchCriticalAlert(
            new FinalVerificationFailureAlert($e, $verificationId)
        );
    }
}
