<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\RevisionRepositoryInterface;
use App\Models\Revision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use App\Events\RevisionCreated;
use App\Events\RevisionApproved;
use App\Events\RevisionRejected;

class RevisionRepository extends BaseRepository implements RevisionRepositoryInterface
{
    public function __construct(Revision $model)
    {
        parent::__construct($model);
    }

    public function createRevision(string $type, int $entityId, array $data): Revision
    {
        $revision = $this->create([
            'revisionable_type' => $type,
            'revisionable_id' => $entityId,
            'user_id' => Auth::id(),
            'status' => 'pending',
            'content' => $data['content'],
            'metadata' => [
                'title' => $data['title'] ?? null,
                'summary' => $data['summary'] ?? null,
                'changes' => $data['changes'] ?? [],
                'editor_notes' => $data['editor_notes'] ?? null,
                'browser' => request()->userAgent(),
                'ip' => request()->ip()
            ]
        ]);

        event(new RevisionCreated($revision));

        return $revision;
    }

    public function getPendingRevisions(string $type = null): Collection
    {
        $query = $this->model
            ->where('status', 'pending')
            ->with(['user', 'reviewer'])
            ->orderBy('created_at', 'desc');

        if ($type) {
            $query->where('revisionable_type', $type);
        }

        return $query->get();
    }

    public function getUserRevisions(int $userId, array $statuses = null): Collection
    {
        $query = $this->model
            ->where('user_id', $userId)
            ->with(['reviewer'])
            ->orderBy('created_at', 'desc');

        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

        return $query->get();
    }

    public function approveRevision(int $revisionId, array $data = []): bool
    {
        $revision = $this->find($revisionId);
        if (!$revision || $revision->status !== 'pending') {
            return false;
        }

        $updated = $this->update($revisionId, [
            'status' => 'approved',
            'reviewer_id' => Auth::id(),
            'reviewed_at' => now(),
            'reviewer_notes' => $data['notes'] ?? null,
            'metadata' => array_merge($revision->metadata, [
                'approval_metadata' => [
                    'timestamp' => now()->toIso8601String(),
                    'browser' => request()->userAgent(),
                    'ip' => request()->ip()
                ]
            ])
        ]);

        if ($updated) {
            event(new RevisionApproved($revision));
        }

        return $updated;
    }

    public function rejectRevision(int $revisionId, string $reason, array $data = []): bool
    {
        $revision = $this->find($revisionId);
        if (!$revision || $revision->status !== 'pending') {
            return false;
        }

        $updated = $this->update($revisionId, [
            'status' => 'rejected',
            'reviewer_id' => Auth::id(),
            'reviewed_at' => now(),
            'reviewer_notes' => $reason,
            'metadata' => array_merge($revision->metadata, [
                'rejection_metadata' => [
                    'reason' => $reason,
                    'details' => $data['details'] ?? null,
                    'suggestions' => $data['suggestions'] ?? null,
                    'timestamp' => now()->toIso8601String(),
                    'browser' => request()->userAgent(),
                    'ip' => request()->ip()
                ]
            ])
        ]);

        if ($updated) {
            event(new RevisionRejected($revision));
        }

        return $updated;
    }

    public function getRevisionHistory(string $type, int $entityId): Collection
    {
        return $this->model
            ->where('revisionable_type', $type)
            ->where('revisionable_id', $entityId)
            ->with(['user', 'reviewer'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function compareRevisions(int $revisionId1, int $revisionId2): array
    {
        $revision1 = $this->find($revisionId1);
        $revision2 = $this->find($revisionId2);

        if (!$revision1 || !$revision2) {
            throw new \InvalidArgumentException('Invalid revision IDs');
        }

        return [
            'content_diff' => $this->generateDiff(
                $revision1->content,
                $revision2->content
            ),
            'metadata_diff' => $this->compareMetadata(
                $revision1->metadata,
                $revision2->metadata
            )
        ];
    }

    public function getRevisionStats(?string $type = null): array
    {
        $query = $this->model->query();

        if ($type) {
            $query->where('revisionable_type', $type);
        }

        return [
            'total' => $query->count(),
            'pending' => $query->where('status', 'pending')->count(),
            'approved' => $query->where('status', 'approved')->count(),
            'rejected' => $query->where('status', 'rejected')->count(),
            'average_review_time' => $query
                ->whereNotNull('reviewed_at')
                ->avg(\DB::raw('TIMESTAMPDIFF(MINUTE, created_at, reviewed_at)')),
            'by_user' => $query
                ->select('user_id', \DB::raw('COUNT(*) as count'))
                ->groupBy('user_id')
                ->with('user:id,name')
                ->get()
                ->pluck('count', 'user.name')
        ];
    }

    protected function generateDiff(array $content1, array $content2): array
    {
        // Implement diff generation logic here
        // Could use libraries like jfcherng/php-diff or sebastian/diff
        return [];
    }

    protected function compareMetadata(array $metadata1, array $metadata2): array
    {
        $changes = [
            'added' => [],
            'removed' => [],
            'modified' => []
        ];

        foreach ($metadata2 as $key => $value) {
            if (!isset($metadata1[$key])) {
                $changes['added'][$key] = $value;
            } elseif ($metadata1[$key] !== $value) {
                $changes['modified'][$key] = [
                    'from' => $metadata1[$key],
                    'to' => $value
                ];
            }
        }

        foreach ($metadata1 as $key => $value) {
            if (!isset($metadata2[$key])) {
                $changes['removed'][$key] = $value;
            }
        }

        return $changes;
    }
}
