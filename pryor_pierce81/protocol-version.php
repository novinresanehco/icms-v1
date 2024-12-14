<?php

namespace App\Core\Version;

class ProtocolVersionControl implements VersionControlInterface
{
    private VersionRegistry $registry;
    private MigrationManager $migrationManager;
    private ValidityChecker $validityChecker;
    private VersionLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        VersionRegistry $registry,
        MigrationManager $migrationManager,
        ValidityChecker $validityChecker,
        VersionLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->registry = $registry;
        $this->migrationManager = $migrationManager;
        $this->validityChecker = $validityChecker;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function validateVersion(VersionContext $context): VersionResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $currentVersion = $this->registry->getCurrentVersion();
            $targetVersion = $context->getTargetVersion();

            $this->validateVersionTransition($currentVersion, $targetVersion);
            $migrationPath = $this->determineMigrationPath($currentVersion, $targetVersion);
            $this->validateMigrationPath($migrationPath);

            $result = new VersionResult([
                'validationId' => $validationId,
                'currentVersion' => $currentVersion,
                'targetVersion' => $targetVersion,
                'migrationPath' => $migrationPath,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (VersionException $e) {
            DB::rollBack();
            $this->handleVersionFailure($e, $validationId);
            throw new CriticalVersionException($e->getMessage(), $e);
        }
    }

    private function validateVersionTransition(Version $current, Version $target): void
    {
        if (!$this->validityChecker->isValidTransition($current, $target)) {
            $this->emergency->handleInvalidTransition($current, $target);
            throw new InvalidVersionTransitionException('Invalid version transition detected');
        }
    }

    private function determineMigrationPath(Version $current, Version $target): MigrationPath
    {
        $path = $this->migrationManager->calculatePath($current, $target);
        
        if (!$path->isValid()) {
            throw new InvalidMigrationPathException('Invalid migration path');
        }
        
        return $path;
    }

    private function handleVersionFailure(VersionException $e, string $validationId): void
    {
        $this->logger->logFailure($e, $validationId);
        
        if ($e->isCritical()) {
            $this->emergency->escalateToHighestLevel();
            $this->alerts->dispatchCriticalAlert(
                new VersionFailureAlert($e, $validationId)
            );
        }
    }
}
