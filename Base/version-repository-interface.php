<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Version;
use Illuminate\Support\Collection;

interface VersionRepositoryInterface extends RepositoryInterface
{
    public function createVersion(
        string $type,
        int $entityId,
        array $data,
        ?int $userId = null
    ): Version;
    
    public function getVersionHistory(string $type, int $entityId): Collection;
    
    public function getVersion(string $type, int $entityId, int $versionNumber): ?Version;
    
    public function getLatestVersion(string $type, int $entityId): ?Version;
    
    public function compareVersions(int $versionId1, int $versionId2): array;
    
    public function revertToVersion(string $type, int $entityId, int $versionNumber): ?Version;
    
    public function pruneVersions(string $type, int $entityId, int $keepLast = 10): int;
}
