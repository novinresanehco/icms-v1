<?php

namespace App\Core\Version;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, HashService};
use App\Core\Exceptions\{VersionException, IntegrityException};

class VersionManager implements VersionInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private HashService $hash;
    
    private const CACHE_TTL = 3600;
    private const MAX_VERSIONS = 100;
    private const CHUNK_SIZE = 1000;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        HashService $hash
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->hash = $hash;
    }

    public function createVersion(string $type, int $entityId, array $data): Version
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreateVersion($type, $entityId, $data),
            ['action' => 'version.create', 'type' => $type, 'entity_id' => $entityId]
        );
    }

    protected function executeCreateVersion(string $type, int $entityId, array $data): Version
    {
        $this->validateVersionData($type, $entityId, $data);

        DB::beginTransaction();
        try {
            // Generate version hash
            $hash = $this->generateVersionHash($type, $entityId, $data);
            
            // Check for duplicate
            if ($this->isDuplicateVersion($type, $entityId, $hash)) {
                throw new VersionException('Duplicate version detected');
            }

            // Create version record
            $version = Version::create([
                'type' => $type,
                'entity_id' => $entityId,
                'data' => $this->prepareVersionData($data),
                'hash' => $hash,
                'created_by' => auth()->id(),
                'metadata' => $this->generateMetadata($type, $entityId)
            ]);

            // Update version count
            $this->updateVersionCount($type, $entityId);

            // Cleanup old versions if needed
            $this->cleanupOldVersions($type, $entityId);

            DB::commit();

            // Clear relevant caches
            $this->clearVersionCache($type, $entityId);

            return $version;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new VersionException('Failed to create version: ' . $e->getMessage());
        }
    }

    public function getVersion(string $type, int $entityId, int $versionId): Version
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeGetVersion($type, $entityId, $versionId),
            ['action' => 'version.get', 'type' => $type, 'entity_id' => $entityId, 'version_id' => $versionId]
        );
    }

    protected function executeGetVersion(string $type, int $entityId, int $versionId): Version
    {
        $cacheKey = "version.{$type}.{$entityId}.{$versionId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($type, $entityId, $versionId) {
            $version = Version::where([
                'type' => $type,
                'entity_id' => $entityId,
                'id' => $versionId
            ])->first();

            if (!$version) {
                throw new VersionException('Version not found');
            }

            // Verify version integrity
            $this->verifyVersionIntegrity($version);

            return $version;
        });
    }

    public function listVersions(string $type, int $entityId): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeListVersions($type, $entityId),
            ['action' => 'version.list', 'type' => $type, 'entity_id' => $entityId]
        );
    }

    protected function executeListVersions(string $type, int $entityId): array
    {
        $cacheKey = "versions.{$type}.{$entityId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($type, $entityId) {
            return Version::where([
                'type' => $type,
                'entity_id' => $entityId
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($version) {
                $this->verifyVersionIntegrity($version);
                return $version;
            })
            ->toArray();
        });
    }

    public function compareVersions(Version $v1, Version $v2): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCompareVersions($v1, $v2),
            ['action' => 'version.compare', 'v1_id' => $v1->id, 'v2_id' => $v2->id]
        );
    }

    protected function executeCompareVersions(Version $v1, Version $v2): array
    {
        $this->verifyVersionIntegrity($v1);
        $this->verifyVersionIntegrity($v2);

        return [
            'added' => $this->findAddedFields($v1, $v2),
            'removed' => $this->findRemovedFields($v1, $v2),
            'modified' => $this->findModifiedFields($v1, $v2)
        ];
    }

    protected function validateVersionData(string $type, int $entityId, array $data): void
    {
        $this->validator->validate([
            'type' => $type,
            'entity_id' => $entityId,
            'data' => $data
        ], [
            'type' => 'required|string|max:50',
            'entity_id' => 'required|integer|min:1',
            'data' => 'required|array'
        ]);
    }

    protected function generateVersionHash(string $type, int $entityId, array $data): string
    {
        return $this->hash->generateHash([
            'type' => $type,
            'entity_id' => $entityId,
            'data' => $data,
            'timestamp' => time()
        ]);
    }

    protected function isDuplicateVersion(string $type, int $entityId, string $hash): bool
    {
        return Version::where([
            'type' => $type,
            'entity_id' => $entityId,
            'hash' => $hash
        ])->exists();
    }

    protected function prepareVersionData(array $data): array
    {
        array_walk_recursive($data, function(&$value) {
            if (is_resource($value)) {
                throw new VersionException('Cannot version resource type');
            }
        });

        return $data;
    }

    protected function generateMetadata(string $type, int $entityId): array
    {
        return [
            'created_at' => time(),
            'user_agent' => request()->userAgent(),
            'ip_address' => request()->ip(),
            'previous_version' => $this->getLatestVersionId($type, $entityId)
        ];
    }

    protected function getLatestVersionId(string $type, int $entityId): ?int
    {
        return Version::where([
            'type' => $type,
            'entity_id' => $entityId
        ])
        ->latest()
        ->value('id');
    }

    protected function updateVersionCount(string $type, int $entityId): void
    {
        $count = Version::where([
            'type' => $type,
            'entity_id' => $entityId
        ])->count();

        Cache::put(
            "version_count.{$type}.{$entityId}",
            $count,
            self::CACHE_TTL
        );
    }

    protected function cleanupOldVersions(string $type, int $entityId): void
    {
        $count = Cache::get("version_count.{$type}.{$entityId}", 0);

        if ($count > self::MAX_VERSIONS) {
            Version::where([
                'type' => $type,
                'entity_id' => $entityId
            ])
            ->orderBy('created_at', 'asc')
            ->limit($count - self::MAX_VERSIONS)
            ->chunk(self::CHUNK_SIZE, function($versions) {
                foreach ($versions as $version) {
                    $version->delete();
                }
            });
        }
    }

    protected function verifyVersionIntegrity(Version $version): void
    {
        $hash = $this->generateVersionHash(
            $version->type,
            $version->entity_id,
            $version->data
        );

        if ($hash !== $version->hash) {
            throw new IntegrityException('Version integrity check failed');
        }
    }

    protected function findAddedFields(Version $v1, Version $v2): array
    {
        return array_diff_key($v2->data, $v1->data);
    }

    protected function findRemovedFields(Version $v1, Version $v2): array
    {
        return array_diff_key($v1->data, $v2->data);
    }

    protected function findModifiedFields(Version $v1, Version $v2): array
    {
        $modified = [];
        foreach ($v1->data as $key => $value) {
            if (isset($v2->data[$key]) && $value !== $v2->data[$key]) {
                $modified[$key] = [
                    'from' => $value,
                    'to' => $v2->data[$key]
                ];
            }
        }
        return $modified;
    }

    protected function clearVersionCache(string $type, int $entityId): void
    {
        Cache::forget("versions.{$type}.{$entityId}");
        Cache::forget("version_count.{$type}.{$entityId}");
    }
}
