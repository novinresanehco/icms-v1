<?php

namespace App\Repositories;

use App\Models\Revision;
use App\Repositories\Contracts\RevisionRepositoryInterface;
use Illuminate\Support\Collection;

class RevisionRepository extends BaseRepository implements RevisionRepositoryInterface
{
    protected array $searchableFields = ['title', 'summary'];
    protected array $filterableFields = ['model_type', 'user_id', 'status'];

    public function createRevision(string $modelType, int $modelId, array $data): ?Revision
    {
        try {
            DB::beginTransaction();

            $revision = $this->create([
                'model_type' => $modelType,
                'model_id' => $modelId,
                'user_id' => auth()->id(),
                'title' => $data['title'] ?? null,
                'content' => $data['content'],
                'metadata' => $data['metadata'] ?? [],
                'summary' => $data['summary'] ?? null
            ]);

            DB::commit();
            return $revision;
        } catch (\Exception $e) {
            DB::rollBack();
            return null;
        }
    }

    public function getRevisions(string $modelType, int $modelId): Collection
    {
        return $this->model->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->with('user')
            ->orderByDesc('created_at')
            ->get();
    }

    public function restore(int $revisionId): bool
    {
        try {
            DB::beginTransaction();
            
            $revision = $this->find($revisionId);
            $model = $revision->model;
            $model->update(['content' => $revision->content]);
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    public function compare(int $fromId, int $toId): array
    {
        $from = $this->find($fromId);
        $to = $this->find($toId);

        return [
            'diff' => $this->generateDiff($from->content, $to->content),
            'from' => $from,
            'to' => $to
        ];
    }

    protected function generateDiff($oldContent, $newContent): array
    {
        // Implement diff generation logic
        return [];
    }
}
