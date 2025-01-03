<?php

namespace App\Core\Version;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Database\DatabaseManager;
use App\Core\Exceptions\VersionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Core Version Management System
 * CRITICAL COMPONENT - Handles all content versioning with history tracking
 */
class VersionManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MonitoringService $monitor;
    private DatabaseManager $database;

    // Version status constants
    private const STATUS_ACTIVE = 'active';
    private const STATUS_ARCHIVED = 'archived';
    private const STATUS_ROLLBACK = 'rollback';

    // Cache configuration
    private const CACHE_VERSION = 'version.';
    private const CACHE_HISTORY = 'version.history.';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MonitoringService $monitor,
        DatabaseManager $database
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->database = $database;
    }

    /**
     * Creates new version with complete validation and tracking
     *
     * @param string $entityType Entity type (content, media, etc)
     * @param int $entityId Entity ID
     * @param array $data Version data
     * @return Version
     * @throws VersionException
     */
    public function createVersion(string $entityType, int $entityId, array $data): Version
    {
        $operationId = $this->monitor->startOperation('version.create');
        
        try {
            // Validate version creation
            $this->security->validateVersionCreation($entityType, $entityId);
            
            DB::beginTransaction();
            
            // Get current version if exists
            $currentVersion = $this->getCurrentVersion($entityType, $entityId);
            
            // Create version data
            $versionData = [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'data' => json_encode($data),
                'version_number' => $currentVersion ? $currentVersion->version_number + 1 : 1,
                'status' => self::STATUS_ACTIVE,
                'created_at' => now(),
                'created_by' => auth()->id(),
                'change_summary' => $this->generateChangeSummary($currentVersion?->data, $data)
            ];
            
            // Store version
            $version = $this->database->store('versions', $versionData);
            
            // Archive current version if exists
            if ($currentVersion) {
                $this->archiveVersion($currentVersion->id);
            }
            
            // Clear relevant caches
            $this->clearVersionCache($entityType, $entityId);
            
            DB::commit();
            
            Log::info('Version created successfully', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'version' => $version->version_number
            ]);
            
            return $version;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('Version creation failed', [
                'error' => $e->getMessage(),
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            
            throw new VersionException(
                'Failed to create version: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Retrieves version history with efficient caching
     */
    public function getVersionHistory(string $entityType, int $entityId): Collection
    {
        $operationId = $this->monitor->startOperation('version.history');
        
        try {
            // Try cache first
            $cacheKey = self::CACHE_HISTORY . "{$entityType}.{$entityId}";
            $cached = $this->cache->get($cacheKey);
            if ($cached) {
                return $cached;
            }
            
            // Get from database
            $history = $this->database->query(
                'SELECT * FROM versions 
                WHERE entity_type = ? AND entity_id = ?
                ORDER BY version_number DESC',
                [$entityType, $entityId]
            );
            
            // Cache results
            $this->cache->set($cacheKey, $history, self::CACHE_TTL);
            
            return $history;
            
        } catch (\Throwable $e) {
            Log::error('Failed to get version history', [
                'error' => $e->getMessage(),
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            
            throw new VersionException(
                'Failed to get version history: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Rolls back to specific version with validation
     */
    public function rollback(string $entityType, int $entityId, int $versionNumber): Version
    {
        $operationId = $this->monitor->startOperation('version.rollback');
        
        try {
            // Validate rollback permissions
            $this->security->validateVersionRollback($entityType, $entityId, $versionNumber);
            
            DB::beginTransaction();
            
            // Get target version
            $targetVersion = $this->getVersion($entityType, $entityId, $versionNumber);
            if (!$targetVersion) {
                throw new VersionException('Target version not found');
            }
            
            // Get current version
            $currentVersion = $this->getCurrentVersion($entityType, $entityId);
            if (!$currentVersion) {
                throw new VersionException('No current version found');
            }
            
            // Create new version from target
            $rollbackData = [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'data' => $targetVersion->data,
                'version_number' => $currentVersion->version_number + 1,
                'status' => self::STATUS_ROLLBACK,
                'created_at' => now(),
                'created_by' => auth()->id(),
                'rolled_back_from' => $versionNumber,
                'change_summary' => "Rolled back to version {$versionNumber}"
            ];
            
            $newVersion = $this->database->store('versions', $rollbackData);
            
            // Archive current version
            $this->archiveVersion($currentVersion->id);
            
            // Clear caches
            $this->clearVersionCache($entityType, $entityId);
            
            DB::commit();
            
            Log::info('Version rollback successful', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'target_version' => $versionNumber,
                'new_version' => $newVersion->version_number
            ]);
            
            return $newVersion;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('Version rollback failed', [
                'error' => $e->getMessage(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'version' => $versionNumber
            ]);
            
            throw new VersionException(
                'Failed to rollback version: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Gets specific version with validation
     */
    public function getVersion(string $entityType, int $entityId, int $versionNumber): ?Version
    {
        $operationId = $this->monitor->startOperation('version.get');
        
        try {
            // Try cache first
            $cacheKey = self::CACHE_VERSION . "{$entityType}.{$entityId}.{$versionNumber}";
            $cached = $this->cache->get($cacheKey);
            if ($cached) {
                return $cached;
            }
            
            // Get from database
            $version = $this->database->findFirst('versions', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'version_number' => $versionNumber
            ]);
            
            if ($version) {
                // Cache result
                $this->cache->set($cacheKey, $version, self::CACHE_TTL);
            }
            
            return $version;
            
        } catch (\Throwable $e) {
            Log::error('Failed to get version', [
                'error' => $e->getMessage(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'version' => $versionNumber
            ]);
            
            throw new VersionException(
                'Failed to get version: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Gets current active version
     */
    private function getCurrentVersion(string $entityType, int $entityId): ?Version
    {
        return $this->database->findFirst('versions', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => self::STATUS_ACTIVE
        ]);
    }

    /**
     * Archives a version
     */
    private function archiveVersion(int $versionId): void
    {
        $this->database->update('versions', $versionId, [
            'status' => self::STATUS_ARCHIVED,
            'archived_at' => now()
        ]);
    }

    /**
     * Generates change summary between versions
     */
    private function generateChangeSummary(?string $oldData, array $newData): string
    {
        if (!$oldData) {
            return 'Initial version';
        }

        $oldData = json_decode($oldData, true);
        $changes = [];

        foreach ($newData as $key => $value) {
            if (!isset($oldData[$key]) || $oldData[$key] !== $value) {
                $changes[] = $key;
            }
        }

        return 'Modified fields: ' . implode(', ', $changes);
    }

    /**
     * Clears version-related cache
     */
    private function clearVersionCache(string $entityType, int $entityId): void
    {
        $this->cache->delete(self::CACHE_HISTORY . "{$entityType}.{$entityId}");
        // Could also clear specific version caches if needed
    }

    /**
     * Compares two versions and returns differences
     */
    public function compareVersions(
        string $entityType,
        int $entityId,
        int $versionA,
        int $versionB
    ): array {
        $operationId = $this->monitor->startOperation('version.compare');
        
        try {
            $versionAData = $this->getVersion($entityType, $entityId, $versionA);
            $versionBData = $this->getVersion($entityType, $entityId, $versionB);
            
            if (!$versionAData || !$versionBData) {
                throw new VersionException('One or both versions not found');
            }
            
            $dataA = json_decode($versionAData->data, true);
            $dataB = json_decode($versionBData->data, true);
            
            return $this->calculateDifferences($dataA, $dataB);
            
        } catch (\Throwable $e) {
            Log::error('Version comparison failed', [
                'error' => $e->getMessage(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'versions' => [$versionA, $versionB]
            ]);
            
            throw new VersionException(
                'Failed to compare versions: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Calculates differences between two data sets
     */
    private function calculateDifferences(array $dataA, array $dataB): array
    {
        $differences = [];
        
        foreach ($dataA as $key => $valueA) {
            if (!isset($dataB[$key])) {
                $differences[$key] = [
                    'type' => 'removed',
                    'old' => $valueA,
                    'new' => null
                ];
            } elseif ($dataB[$key] !== $valueA) {
                $differences[$key] = [
                    'type' => 'modified',
                    'old' => $valueA,
                    'new' => $dataB[$key]
                ];
            }
        }
        
        foreach ($dataB as $key => $valueB) {
            if (!isset($dataA[$key])) {
                $differences[$key] = [
                    'type' => 'added',
                    'old' => null,
                    'new' => $valueB
                ];
            }
        }
        
        return $differences;
    }
}
