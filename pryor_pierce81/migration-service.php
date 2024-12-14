<?php

namespace App\Core\Migration;

class CriticalMigrationService implements MigrationInterface 
{
    private StateValidator $stateValidator;
    private MigrationEngine $migrationEngine;
    private RollbackManager $rollbackManager;
    private MigrationLogger $logger;
    private EmergencyProtocol $emergency;
    private IntegrityVerifier $integrityVerifier;

    public function __construct(
        StateValidator $stateValidator,
        MigrationEngine $migrationEngine,
        RollbackManager $rollbackManager,
        MigrationLogger $logger,
        EmergencyProtocol $emergency,
        IntegrityVerifier $integrityVerifier
    ) {
        $this->stateValidator = $stateValidator;
        $this->migrationEngine = $migrationEngine;
        $this->rollbackManager = $rollbackManager;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->integrityVerifier = $integrityVerifier;
    }

    public function executeMigration(MigrationContext $context): MigrationResult
    {
        $migrationId = $this->initializeMigration($context);
        
        try {
            DB::beginTransaction();

            $currentState = $this->stateValidator->captureState();
            $this->validatePreMigrationState($currentState);

            $migrationPlan = $this->migrationEngine->createPlan($context, $currentState);
            $this->validateMigrationPlan($migrationPlan);

            // Create rollback point before migration
            $rollbackPoint = $this->rollbackManager->createRollbackPoint($currentState);

            $newState = $this->migrationEngine->executeMigration($migrationPlan);
            $this->validatePostMigrationState($newState);

            $result = new MigrationResult([
                'migrationId' => $migrationId,
                'previousState' => $currentState,
                'newState' => $newState,
                'rollbackPoint' => $rollbackPoint,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (MigrationException $e) {
            DB::rollBack();
            $this->handleMigrationFailure($e, $migrationId, $rollbackPoint ?? null);
            throw new CriticalMigrationException($e->getMessage(), $e);
        }
    }

    private function validatePreMigrationState(SystemState $state): void
    {
        if (!$this->stateValidator->validateState($state)) {
            throw new InvalidStateException('Pre-migration state validation failed');
        }

        if (!$this->integrityVerifier->verifyStateIntegrity($state)) {
            $this->emergency->handleIntegrityFailure($state);
            throw new IntegrityException('Pre-migration state integrity verification failed');
        }
    }

    private function validateMigrationPlan(MigrationPlan $plan): void
    {
        if (!$this->migrationEngine->validatePlan($plan)) {
            throw new InvalidPlanException('Migration plan validation failed');
        }
    }

    private function handleMigrationFailure(
        MigrationException $e,
        string $migrationId,
        ?RollbackPoint $rollbackPoint
    ): void {
        $this->logger->logFailure($e, $migrationId);

        if ($rollbackPoint) {
            try {
                $this->rollbackManager->executeRollback($rollbackPoint);
            } catch (RollbackException $re) {
                $this->emergency->handleRollbackFailure($re, $rollbackPoint);
                throw new CriticalRollbackException('Migration rollback failed', previous: $re);
            }
        }

        $this->emergency->handleMigrationFailure($e, $migrationId);
    }
}
