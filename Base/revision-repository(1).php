<?php

namespace App\Repositories;

use App\Models\Revision;
use App\Repositories\Contracts\RevisionRepositoryInterface;
use Illuminate\Support\Collection;

class RevisionRepository extends BaseRepository implements RevisionRepositoryInterface
{
    protected array $searchableFields = ['revisionable_type', 'revisionable_id'];
    protected array $filterableFields = ['user_id', 'type'];
    protected array $relationships = ['user'];

    public function getHistory(string $type, int $id): Collection
    {
        return Cache::remember(
            $this->getCacheKey("history.{$type}.{$id}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('revisionable_type', $type)
                ->where('revisionable_id', $id)
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function revert(int $revisionId): void
    {
        try {
            DB::beginTransaction();
            
            $revision = $this->findOrFail($revisionId);
            $revisionable = $revision->revisionable;
            
            if ($revisionable) {
                $revisionable->fill($revision->old_values)->save();
                
                $this->create([
                    'revisionable_type' => get_class($revisionable),
                    'revisionable_id' => $revisionable->id,
                    'user_id' => auth()->id(),
                    'type' => 'revert',
                    'old_values' => $revisionable->getAttributes(),
                    'new_values' => $revision->old_values
                ]);
            }
            
            DB::commit();
            $this->clearModelCache();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to revert revision: {$e->getMessage()}");
        }
    }

    public function pruneOldRevisions(int $keepLast = 10): int
    {
        $deleted = 0;
        
        $this->model->select('revisionable_type', 'revisionable_id')
            ->groupBy('revisionable_type', 'revisionable_id')
            ->get()
            ->each(function ($group) use ($keepLast, &$deleted) {
                $revisions = $this->model
                    ->where('revisionable_type', $group->revisionable_type)
                    ->where('revisionable_id', $group->revisionable_id)
                    ->orderBy('created_at', 'desc')
                    ->get();
                    
                if ($revisions->count() > $keepLast) {
                    $toDelete = $revisions->slice($keepLast);
                    $deleted += $toDelete->count();
                    $this->model->whereIn('id', $toDelete->pluck('id'))->delete();
                }
            });
            
        $this->clearModelCache();
        return $deleted;
    }
}
