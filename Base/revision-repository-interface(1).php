<?php

namespace App\Repositories\Contracts;

use App\Models\Revision;
use Illuminate\Support\Collection;

interface RevisionRepositoryInterface
{
    public function createRevision(string $type, int $modelId, array $data): Revision;
    public function getRevisions(string $type, int $modelId): Collection;
    public function compareRevisions(int $fromId, int $toId): array;
    public function revertTo(int $revisionId): bool;
    public function getRevisionsByUser(int $userId): Collection;
    public function getLatestRevisions(int $limit = 10): Collection;
    public function pruneRevisions(string $type, int $modelId, int $keep = 10): bool;
    public function getRevisionDetails(int $revisionId): array;
}
