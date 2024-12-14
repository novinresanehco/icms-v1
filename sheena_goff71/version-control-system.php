<?php

namespace App\Core\Version;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityContext;
use App\Core\Services\{ValidationService, DiffService, AuditService};
use App\Core\Exceptions\{VersionException, SecurityException};

class VersionManager implements VersionManagerInterface
{
    private ValidationService $validator;
    private DiffService $differ;
    private AuditService $audit;
    private array $config;

    public function __construct(
        ValidationService $validator,
        DiffService $differ,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->differ = $differ;
        $this->audit = $audit;
        $this->config = config('version');
    }

    public function createVersion(Versionable $entity, SecurityContext $context): Version
    {
        return DB::transaction(function() use ($entity, $context) {
            try {
                // Validate entity
                $this->validateEntity($entity);

                // Calculate changes
                $changes = $this->calculateChanges($entity);

                // Create version object
                $version = $this->createVersionObject($entity, $changes);

                // Store version
                $this->storeVersion($version);

                // Update entity metadata
                $this->updateEntityMetadata($entity, $version);

                // Log version creation
                $this->audit->logVersionCreation($version, $context);

                return $version;

            } catch (\Exception $e) {
                $this->handleVersionFailure($e, $entity, $context);
                throw new VersionException('Version creation failed: ' . $e->getMessage());
            }
        });
    }

    public function revert(string $entityId, string $versionId, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($entityId, $versionId, $context) {
            try {
                // Validate revert request
                $this->validateRevertRequest($entityId, $versionId);

                // Verify revert permissions
                $this->verifyRevertPermissions($entityId, $versionId, $context);

                // Create backup point
                $this->createBackupPoint($entityId);

                // Execute revert
                $success = $this->executeRevert($entityId, $versionId);

                // Verify entity state
                $this->verifyEntityState($entityId);

                // Log revert operation
                $this->audit->logVersionRevert($entityId, $versionId, $context);

                return $success;

            } catch (\Exception $e) {
                $this->handleRevertFailure($e, $entityId, $versionId, $context);
                throw new VersionException('Version revert failed: ' . $e->getMessage());
            }
        });
    }

    public function compareVersions(string $versionId1, string $versionId2, SecurityContext $context): array
    {
        try {
            // Validate comparison request
            $this->validateComparisonRequest($versionId1, $versionId2);

            // Verify comparison permissions
            $this->verifyComparisonPermissions($versionId1, $versionId2, $context);

            // Load versions
            $version1 = $this->loadVersion($versionId1);
            $version2 = $this->loadVersion($versionId2);

            // Generate diff
            $diff = $this->generateDiff($version1, $version2);

            // Process comparison results
            $results = $this->processComparisonResults($diff);

            // Log comparison
            $this->audit->logVersionComparison($versionId1, $versionId2, $context);

            return $results;

        } catch (\Exception $e) {
            $this->handleComparisonFailure($e, $versionId1, $versionId2, $context);
            throw new VersionException('Version comparison failed: ' . $e->getMessage());
        }
    }

    private function validateEntity(Versionable $entity): void
    {
        if (!$this->validator->validateVersionableEntity($entity)) {
            throw new VersionException('Invalid versionable entity');
        }
    }

    private function calculateChanges(Versionable $entity): array
    {
        // Get current state
        $currentState = $this->getCurrentState($entity);

        // Get previous version
        $previousVersion = $this->getPreviousVersion($entity);

        // Calculate diff
        return $this->differ->calculateDiff($previousVersion, $currentState);
    }

    private function createVersionObject(Versionable $entity, array $changes): Version
    {
        return new Version([
            'entity_id' => $entity->getId(),
            'version_number' => $this->generateVersionNumber($entity),
            'changes' => $changes,
            'metadata' => $this->generateVersionMetadata($entity),
            'hash' => $this->calculateVersionHash($changes),
            'created_at' => now()
        ]);
    }

    private function storeVersion(Version $version): void
    {
        DB::table('versions')->insert([
            'version_id' => $version->getId(),
            'entity_id' => $version->getEntityId(),
            'version_data' => json_encode($version->getData()),
            'created_at' => $version->getCreatedAt()
        ]);
    }

    private function updateEntityMetadata(Versionable $entity, Version $version): void
    {
        $entity->setCurrentVersion($version->getId());
        $entity->incrementVersionCount();
        $entity->setLastModified(now());
    }

    private function validateRevertRequest(string $entityId, string $versionId): void
    {
        if (!$this->validator->validateRevertRequest($entityId, $versionId)) {
            throw new VersionException('Invalid revert request');
        }
    }

    private function verifyRevertPermissions(string $entityId, string $versionId, SecurityContext $context): void
    {
        if (!$this->hasRevertPermission($entityId, $versionId, $context)) {
            throw new SecurityException('Revert permission denied');
        }
    }

    private function createBackupPoint(string $entityId): void
    {
        $entity = $this->loadEntity($entityId);
        $backup = new EntityBackup($entity);
        $backup->create();
    }

    private function executeRevert(string $entityId, string $versionId): bool
    {
        // Load version
        $version = $this->loadVersion($versionId);

        // Apply changes
        $entity = $this->loadEntity($entityId);
        return $this->applyVersionChanges($entity, $version);
    }

    private function verifyEntityState(string $entityId): void
    {
        $entity = $this->loadEntity($entityId);
        if (!$this->validator->validateEntityState($entity)) {
            throw new VersionException('Invalid entity state after revert');
        }
    }

    private function generateDiff(Version $version1, Version $version2): array
    {
        return $this->differ->generateDetailedDiff(
            $version1->getData(),
            $version2->getData()
        );
    }

    private function processComparisonResults(array $diff): array
    {
        $processor = new DiffProcessor($this->config['diff_options']);
        return $processor->process($diff);
    }

    private function handleVersionFailure(\Exception $e, Versionable $entity, SecurityContext $context): void
    {
        $this->audit->logVersionFailure($entity, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleRevertFailure(\Exception $e, string $entityId, string $versionId, SecurityContext $context): void
    {
        $this->audit->logRevertFailure($entityId, $versionId, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleComparisonFailure(\Exception $e, string $versionId1, string $versionId2, SecurityContext $context): void
    {
        $this->audit->logComparisonFailure($versionId1, $versionId2, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
