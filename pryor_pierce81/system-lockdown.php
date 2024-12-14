<?php

namespace App\Core\Security;

class SystemLockdownService implements LockdownInterface
{
    private AccessController $accessController;
    private ProcessManager $processManager;
    private StateManager $stateManager;
    private LockdownLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        AccessController $accessController,
        ProcessManager $processManager,
        StateManager $stateManager,
        LockdownLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->accessController = $accessController;
        $this->processManager = $processManager;
        $this->stateManager = $stateManager;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function initiateLockdown(LockdownContext $context): LockdownResult
    {
        $lockdownId = $this->initializeLockdown($context);
        
        try {
            DB::beginTransaction();

            $this->suspendAllAccess();
            $this->freezeSystemState();
            $this->terminateNonCriticalProcesses();

            $result = new LockdownResult([
                'lockdownId' => $lockdownId,
                'status' => LockdownStatus::ENGAGED,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (LockdownException $e) {
            DB::rollBack();
            $this->handleLockdownFailure($e, $lockdownId);
            throw new CriticalLockdownException($e->getMessage(), $e);
        }
    }

    private function suspendAllAccess(): void
    {
        $this->accessController->suspendAllAccess([
            'level' => AccessLevel::TOTAL_LOCKDOWN,
            'exceptions' => ['emergency_protocols']
        ]);
    }

    private function freezeSystemState(): void
    {
        if (!$this->stateManager->freezeState()) {
            throw new StateFreezingException('Failed to freeze system state');
        }
    }

    private function terminateNonCriticalProcesses(): void
    {
        $processes = $this->processManager->terminateNonCritical();
        
        foreach ($processes as $process) {
            if ($process->isActive()) {
                $this->emergency->handleProcessTerminationFailure($process);
            }
        }
    }

    private function handleLockdownFailure(
        LockdownException $e,
        string $lockdownId
    ): void {
        $this->logger->logFailure($e, $lockdownId);
        
        $this->alerts->dispatchCriticalAlert(
            new LockdownFailureAlert($e, $lockdownId)
        );

        $this->emergency->engageFailsafeLockdown();
    }
}
