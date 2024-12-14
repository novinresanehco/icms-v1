<?php

namespace App\Core\Version;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\VersionEvent;
use App\Core\Exceptions\{VersionException, SecurityException};
use Illuminate\Support\Facades\{DB, Log};

class VersionManager implements VersionInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $config;
    private array $trackedChanges = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = array_merge([
            'max_versions' => 100,
            'diff_algorithm' => 'myers',
            'compression' => true,
            'retention_period' => 90,
            'validate_integrity' => true
        ], $config);
    }

    public function createVersion(string $type, int $entityId, array $data): Version
    {
        return $this->security->executeCriticalOperation(
            function() use ($type, $entityId, $data) {
                DB::beginTransaction();
                try {
                    $this->validateVersionData($data);
                    
                    $version = $this->createVersionRecord($type, $entityId, $data);
                    
                    $this->storeVersionData($version, $data);
                    
                    $this->cleanupOldVersions($type, $entityId);
                    
                    event(new VersionEvent('version_created', $version));
                    
                    DB::commit();
                    return $version;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->handleVersionError($e, $type, $entityId);
                    throw $e;
                }
            },
            ['operation' => 'create_version']
        );
    }

    public function getVersion(string $type, int $entityId, int $version): array
    {
        return $this->security->executeCriticalOperation(
            function() use ($type, $entityId, $version) {
                $cacheKey = $this->getVersionCacheKey($type, $entityId, $version);
                
                return $this->cache->remember($cacheKey, 3600, function() use ($type, $entityId, $version) {
                    $versionData = $this->loadVersionData($type, $entityId, $version);
                    
                    if ($this->config['validate_integrity']) {
                        $this->validateVersionIntegrity($versionData);
                    }
                    
                    return $versionData;
                });
            },
            ['operation' => 'get_version']
        );
    }

    public function revertToVersion(string $type, int $entityId, int $version): void
    {
        $this->security->executeCriticalOperation(
            function() use ($type, $entityId, $version) {
                DB::beginTransaction();
                try {
                    $versionData = $this->getVersion($type, $entityId, $version);
                    
                    $this->validateReversion($type, $entityId, $version);
                    
                    $this->createVersion($type, $entityId, [
                        'data' => $versionData['data'],
                        'metadata' => array_merge(
                            $versionData['metadata'] ?? [],
                            ['reverted_from' => $version]
                        )
                    ]);
                    
                    event(new VersionEvent('version_reverted', [
                        'type' => $type,
                        'entity_id' => $entityId,
                        'version' => $version
                    ]));
                    
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new VersionException(
                        'Version reversion failed: ' . $e->getMessage()
                    );
                }
            },
            ['operation' => 'revert_version']
        );
    }

    public function compareVersions(
        string $type, 
        int $entityId, 
        int $versionA, 
        int $versionB
    ): array {
        return $this->security->executeCriticalOperation(
            function() use ($type, $entityId, $versionA, $versionB) {
                $dataA = $this->getVersion($type, $entityId, $versionA);
                $dataB = $this->getVersion($type, $entityId, $versionB);
                
                return $this->generateDiff(
                    $dataA['data'],
                    $dataB['data']
                );
            },
            ['operation' => 'compare_versions']
        );
    }

    protected function createVersionRecord(
        string $type, 
        int $entityId, 
        array $data
    ): Version {
        $version = new Version([
            'type' => $type,
            'entity_id' => $entityId,
            'version' => $this->getNextVersion($type, $entityId),
            'hash' => $this->generateHash($data),
            'created_at' => now(),
            'created_by' => auth()->id()
        ]);
        
        $version->save();
        return $version;
    }

    protected function storeVersionData(Version $version, array $data): void
    {
        $preparedData = $this->prepareDataForStorage($data);
        
        if ($this->config['compression']) {
            $preparedData = $this->compressData($preparedData);
        }
        
        DB::table('version_data')->insert([
            'version_id' => $version->id,
            'data' => $preparedData,
            'created_at' => now()
        ]);
    }

    protected function loadVersionData(
        string $type, 
        int $entityId, 
        int $version
    ): array {
        $versionRecord = DB::table('versions')
            ->where('type', $type)
            ->where('entity_id', $entityId)
            ->where('version', $version)
            ->first();
            
        if (!$versionRecord) {
            throw new VersionException('Version not found');
        }
        
        $data = DB::table('version_data')
            ->where('version_id', $versionRecord->id)
            ->value('data');
            
        if ($this->config['compression']) {
            $data = $this->decompressData($data);
        }
        
        return json_decode($data, true);
    }

    protected function validateVersionData(array $data): void
    {
        if (empty($data['data'])) {
            throw new VersionException('Version data is required');
        }

        if (isset($data['metadata'])) {
            $this->validateMetadata($data['metadata']);
        }

        $this->validateDataSize($data);
    }

    protected function validateMetadata(array $metadata): void
    {
        $allowedKeys = ['author', 'comment', 'tags', 'status'];
        
        foreach ($metadata as $key => $value) {
            if (!in_array($key, $allowedKeys)) {
                throw new VersionException("Invalid metadata key: {$key}");
            }
        }
    }

    protected function validateDataSize(array $data): void
    {
        $size = strlen(serialize($data));
        
        if ($size > 10 * 1024 * 1024) { // 10MB
            throw new VersionException('Version data too large');
        }
    }

    protected function validateVersionIntegrity(array $versionData): void
    {
        $hash = $this->generateHash($versionData['data']);
        
        if ($hash !== $versionData['hash']) {
            throw new SecurityException('Version integrity check failed');
        }
    }

    protected function validateReversion(
        string $type, 
        int $entityId, 
        int $version
    ): void {
        $latestVersion = $this->getLatestVersion($type, $entityId);
        
        if ($version > $latestVersion) {
            throw new VersionException('Cannot revert to future version');
        }
    }

    protected function getNextVersion(string $type, int $entityId): int
    {
        return $this->getLatestVersion($type, $entityId) + 1;
    }

    protected function getLatestVersion(string $type, int $entityId): int
    {
        return DB::table('versions')
            ->where('type', $type)
            ->where('entity_id', $entityId)
            ->max('version') ?? 0;
    }

    protected function cleanupOldVersions(string $type, int $entityId): void
    {
        $versions = DB::table('versions')
            ->where('type', $type)
            ->where('entity_id', $entityId)
            ->orderBy('version', 'desc')
            ->get();
            
        if (count($versions) > $this->config['max_versions']) {
            $versionsToDelete = $versions->slice($this->config['max_versions']);
            
            foreach ($versionsToDelete as $version) {
                DB::table('version_data')
                    ->where('version_id', $version->id)
                    ->delete();
                    
                DB::table('versions')
                    ->where('id', $version->id)
                    ->delete();
            }
        }
    }

    protected function generateHash(array $data): string
    {
        return hash('sha256', serialize($data));
    }

    protected function compressData(string $data): string
    {
        return gzencode($data, 9);
    }

    protected function decompressData(string $data): string
    {
        return gzdecode($data);
    }

    protected function prepareDataForStorage(array $data): string
    {
        return json_encode($data);
    }

    protected function generateDiff(array $dataA, array $dataB): array
    {
        if ($this->config['diff_algorithm'] === 'myers') {
            return $this->generateMyersDiff($dataA, $dataB);
        }
        
        return $this->generateSimpleDiff($dataA, $dataB);
    }

    protected function getVersionCacheKey(
        string $type,
        int $entityId,
        int $version
    ): string {
        return "version.{$type}.{$entityId}.{$version}";
    }

    protected function handleVersionError(
        \Exception $e,
        string $type,
        int $entityId
    ): void {
        Log::error('Version operation failed', [
            'type' => $type,
            'entity_id' => $entityId,
            'error' => $e->getMessage()
        ]);
    }
}
