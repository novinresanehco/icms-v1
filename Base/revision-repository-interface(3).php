<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Revision;
use Illuminate\Support\Collection;

interface RevisionRepositoryInterface extends RepositoryInterface
{
    public function createRevision(string $type, int $entityId, array $data): Revision;
    
    public function getPendingRevisions(string $type = null): Collection;
    
    public function getUserRevisions(int $userId, array $statuses = null): Collection;
    
    public function approveRevision(int $revisionId, array $data = []): bool;
    
    public function rejectRevision(int $revisionId, string $reason, array $data = []): bool;
    
    public function getRevisionHistory(string $type, int $entityId): Collection;
    
    public function compareRevisions(int $revisionId1, int $revisionId2): array;
    
    public function getRevisionStats(?string $type = null): array;
}
