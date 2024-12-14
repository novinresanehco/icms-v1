<?php

namespace App\Core\Verification;

class CriticalVerificationExecutor implements VerificationExecutorInterface
{
    private VerificationChain $verificationChain;
    private RequirementValidator $requirementValidator;
    private ComplianceVerifier $complianceVerifier;
    private VerificationLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        VerificationChain $verificationChain,
        RequirementValidator $requirementValidator,
        ComplianceVerifier $complianceVerifier,
        VerificationLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->verificationChain = $verificationChain;
        $this->requirementValidator = $requirementValidator;
        $this->complianceVerifier = $complianceVerifier;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function executeVerification(VerificationContext $context): VerificationResult
    {
        $verificationId = $this->initializeVerification($context);
        
        try {
            DB::beginTransaction();

            $requirements = $this->requirementValidator->validate($context);
            $this->verifyRequirements($requirements);

            $verificationSteps = $this->verificationChain->getSteps();
            $verificationResults = $this->executeVerificationSteps($verificationSteps);

            $compliance = $this->complianceVerifier->verify($verificationResults);
            $this->validateCompliance($compliance);

            $result = new VerificationResult([
                'verificationId' => $verificationId,
                'requirements' => $requirements,
                'results' => $verificationResults,
                'compliance' => $compliance,
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

    private function executeVerificationSteps(array $steps): array
    {
        $results = [];
        
        foreach ($steps as $step) {
            $stepResult = $step->execute();
            
            if (!$stepResult->isPassed()) {
                $this->emergency->handleVerificationStepFailure($step, $stepResult);
                throw new VerificationStepException("Verification step failed: {$step->getName()}");
            }
            
            $results[] = $stepResult;
        }
        
        return $results;
    }

    private function validateCompliance(Compliance $compliance): void
    {
        if (!$compliance->isFullyCompliant()) {
            $this->emergency->handleNonCompliance($compliance);
            throw new ComplianceException('Full compliance verification failed');
        }
    }

    private function handleVerificationFailure(
        VerificationException $e,
        string $verificationId
    ): void {
        $this->logger->logFailure($e, $verificationId);
        
        if ($e->isCritical()) {
            $this->emergency->escalateToHighestLevel();
            $this->alerts->dispatchCriticalAlert(
                new VerificationFailureAlert($e, $verificationId)
            );
        }
    }
}
