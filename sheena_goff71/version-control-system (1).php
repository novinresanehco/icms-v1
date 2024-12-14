<?php

namespace App\Core\VersionControl;

class VersionControlSystem implements VersionControlInterface
{
    private ChangeManager $changes;
    private VersionValidator $validator;
    private StateTracker $tracker;
    private SecurityGuard $security;
    private EmergencyHandler $emergency;

    public function __construct(
        ChangeManager $changes,
        VersionValidator $validator,
        StateTracker $tracker,
        SecurityGuard $security,
        EmergencyHandler $emergency
    ) {
        $this->changes = $changes;
        $this->validator = $validator;
        $this->tracker = $tracker;
        $this->security = $security;
        $this->emergency = $emergency;
    }

    public function commitChange(SystemChange $change): ChangeResult
    {
        $changeId = $this->initializeChange();
        DB::beginTransaction();

        try {
            // Validate change
            $validation = $this->validator->validateChange($change);
            if (!$validation->isValid()) {
                throw new ValidationException($validation->getViolations());
            }

            // Security clearance
            $clearance = $this->security->authorizeChange($change);
            if (!$clearance->isGranted()) {
                throw new SecurityException($clearance->getReasons());
            }

            // Create change point
            $changePoint = $this->tracker->createChangePoint();

            // Execute change
            $result = $this->executeChange($change, $changePoint, $changeId);

            // Verify change
            $this->verifyChange($result);

            $this->tracker->recordChange($changeId, $result);
            DB::commit();

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleChangeFailure($changeId, $change, $e);
            throw $e;
        }
    }

    private function executeChange(
        SystemChange $change,
        ChangePoint $point,
        string $changeId
    ): ChangeResult {
        // Apply change with version control
        $version = $this->changes->applyChange($change, $point);

        // Verify version integrity
        if (!$this->validator->verifyVersionIntegrity($version)) {
            throw new IntegrityException('Version integrity check failed');
        }

        // Validate system state
        $state = $this->tracker->validateSystemState($version);
        if (!$state->isValid()) {
            throw new StateException('Invalid system state after change');
        }

        return new ChangeResult(
            success: true,
            changeId: $changeId,
            version: $version,
            state: $state
        );
    }

    private function verifyChange(ChangeResult $result): void
    {
        // Verify change integrity
        if (!$this->validator->verifyChangeIntegrity($result)) {
            throw new IntegrityException('Change integrity check failed');
        }

        // Verify version compatibility
        if (!$this->validator->verifyVersionCompatibility($result->getVersion())) {
            throw new CompatibilityException('Version compatibility check failed');
        }

        // Verify security requirements
        if (!$this->security->verifyChangeCompliance($result)) {
            throw new SecurityException('Change security requirements not met');
        }
    }

    private function handleChangeFailure(
        string $changeId,
        SystemChange $change,
        \Exception $e
    ): void {
        Log::critical('Change execution failed', [
            'change_id' => $changeId,
            'change' => $change->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Handle emergency
        $this->emergency->handleChangeFailure(
            $changeId,
            $change,
            $e
        );

        // Attempt rollback if possible
        if ($change->isReversible()) {
            $this->attemptRollback($changeId, $change);
        }
    }

    private function attemptRollback(
        string $changeId,
        SystemChange $change
    ): void {
        try {
            $rollbackPlan = $this->changes->createRollbackPlan($change);
            $this->changes->executeRollback($rollbackPlan);
        } catch (\Exception $rollbackError) {
            Log::emergency('Change rollback failed', [
                'change_id' => $changeId,
                'error' => $rollbackError->getMessage()
            ]);
            $this->emergency->escalateFailure($changeId, $rollbackError);
        }
    }

    public function getVersionHistory(): VersionHistory
    {
        try {
            $history = $this->tracker->getCompleteHistory();

            // Verify history integrity
            if (!$this->validator->verifyHistoryIntegrity($history)) {
                throw new IntegrityException('Version history integrity compromised');
            }

            return $history;

        } catch (\Exception $e) {
            $this->handleHistoryFailure($e);
            throw new HistoryException(
                'Version history retrieval failed',
                previous: $e
            );
        }
    }

    private function initializeChange(): string
    {
        return Str::uuid();
    }

    private function handleHistoryFailure(\Exception $e): void
    {
        $this->emergency->handleHistoryFailure([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);
    }
}
