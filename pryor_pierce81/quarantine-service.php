<?php

namespace App\Core\Quarantine;

class QuarantineService implements QuarantineInterface
{
    private IsolationManager $isolationManager;
    private ContainmentValidator $validator;
    private SecurityBoundary $boundary;
    private QuarantineLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        IsolationManager $isolationManager,
        ContainmentValidator $validator,
        SecurityBoundary $boundary,
        QuarantineLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->isolationManager = $isolationManager;
        $this->validator = $validator;
        $this->boundary = $boundary;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function isolateData(array $compromisedData, QuarantineLevel $level): QuarantineResult
    {
        $quarantineId = $this->initializeQuarantine();
        
        try {
            DB::beginTransaction();

            $this->validateQuarantineRequest($compromisedData, $level);
            $containment = $this->isolationManager->createContainment($compromisedData, $level);
            
            $this->validateContainment($containment);
            $this->establishSecurityBoundary($containment);

            $result = new QuarantineResult([
                'quarantineId' => $quarantineId,
                'containment' => $containment,
                'level' => $level,
                'status' => QuarantineStatus::ISOLATED,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (QuarantineException $e) {
            DB::rollBack();
            $this->handleQuarantineFailure($e, $quarantineId);
            throw new CriticalQuarantineException($e->getMessage(), $e);
        }
    }

    private function validateContainment(Containment $containment): void
    {
        if (!$this->validator->validateContainment($containment)) {
            $this->emergency->handleContainmentBreach($containment);
            throw new ContainmentBreachException('Quarantine containment validation failed');
        }
    }

    private function establishSecurityBoundary(Containment $containment): void
    {
        $boundary = $this->boundary->establish($containment);
        
        if (!$boundary->isSecure()) {
            $this->emergency->handleInsecureBoundary($boundary);
            throw new InsecureBoundaryException('Failed to establish secure quarantine boundary');
        }
    }

    private function handleQuarantineFailure(QuarantineException $e, string $quarantineId): void
    {
        $this->logger->logFailure($e, $quarantineId);
        
        if ($e->isCritical()) {
            $this->emergency->escalateToHighestLevel();
            $this->alerts->dispatchCriticalAlert(
                new QuarantineFailureAlert($e, $quarantineId)
            );
        }
    }
}
