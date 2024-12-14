<?php

namespace App\Repositories;

use App\Models\Revision;
use App\Repositories\Contracts\RevisionRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SebastianBergmann\Diff\Differ;

class RevisionRepository extends BaseRepository implements RevisionRepositoryInterface
{
    protected Differ $differ;

    public function __construct()
    {
        parent::__construct();
        $this->differ = new Differ();
    }

    protected function getModel(): Model
    {
        return new Revision();
    }

    public function createRevision(string $type, int $modelId, array $data): Revision
    {
        return $this->model->create([
            'revisionable_type' => $type,
            'revisionable_id' => $modelId,
            'user_id' => auth()->id(),
            'data' => json_encode($data),
            'created_at' => now()
        ]);
    }

    public function getRevisions(string $type, int $modelId): Collection
    {
        return $this->model->where('revisionable_type', $type)
            ->where('revisionable_id', $modelId)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function compareRevisions(int $fromId, int $toId): array
    {
        $fromRevision = $this->model->findOrFail($fromId);
        $toRevision = $this->model->findOrFail($toId);

        $fromData = json_decode($fromRevision->data, true);
        $toData = json_decode($toRevision->data, true);

        $differences = [];

        foreach ($toData as $key => $value) {
            if (!isset($fromData[$key]) || $fromData[$key] !== $value) {
                if (is_string($value) && isset($fromData[$key])) {
                    $differences[$key] = $this->differ->diff($fromData[$key], $value);
                } else {
                    $differences[$key] = [
                        'old' => $fromData[$key] ?? null,
                        'new' => $value
                    ];
                }
            }
        }

        return $differences;
    }

    public function revertTo(int $revisionId): bool
    {
        $revision = $this->model->findOrFail($revisionId);
        $data = json_decode($revision->data, true);

        $model = $revision->revisionable_type::findOrFail($revision->revisionable_id);
        
        try {
            $model->update($data);
            
            $this->createRevision(
                $revision->revisionable_type,
                $revision->revisionable_id,
                $data
            );
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getRevisionsByUser(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->with(['revisionable'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getLatestRevisions(int $limit = 10): Collection
    {
        return $this->model->with(['user', 'revisionable'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function pruneRevisions(string $type, int $modelId, int $keep = 10): bool
    {
        try {
            $revisions = $this->model->where('revisionable_type', $type)
                ->where('revisionable_id', $modelId)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($revisions->count() <= $keep) {
                return true;
            }

            $toDelete = $revisions->slice($keep);
            
            foreach ($toDelete as $revision) {
                $revision->delete();
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getRevisionDetails(int $revisionId): array
    {
        $revision = $this->model->with(['user', 'revisionable'])->findOrFail($revisionId);
        $data = json_decode($revision->data, true);

        $previousRevision = $this->model->where('revisionable_type', $revision->revisionable_type)
            ->where('revisionable_id', $revision->revisionable_id)
            ->where('created_at', '<', $revision->created_at)
            ->orderBy('created_at', 'desc')
            ->first();

        return [
            'revision' => $revision,
            'changes' => $previousRevision 
                ? $this->compareRevisions($previousRevision->id, $revision->id)
                : array_map(fn($value) => ['old' => null, 'new' => $value], $data),
            'previous' => $previousRevision,
            'data' => $data
        ];
    }
}
