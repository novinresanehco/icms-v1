<?php

namespace App\Core\Repositories;

use App\Models\Revision;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class RevisionRepository extends AdvancedRepository
{
    protected $model = Revision::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getForModel($model): Collection
    {
        return $this->executeQuery(function() use ($model) {
            return $this->model
                ->where('revisionable_type', get_class($model))
                ->where('revisionable_id', $model->id)
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function createRevision($model, array $changes, string $reason = null): Revision
    {
        return $this->executeTransaction(function() use ($model, $changes, $reason) {
            return $this->create([
                'revisionable_type' => get_class($model),
                'revisionable_id' => $model->id,
                'user_id' => auth()->id(),
                'changes' => $changes,
                'reason' => $reason,
                'created_at' => now()
            ]);
        });
    }

    public function restore(Revision $revision): void
    {
        $this->executeTransaction(function() use ($revision) {
            $model = $revision->revisionable;
            foreach ($revision->changes as $field => $value) {
                $model->$field = $value['old'];
            }
            $model->save();
            
            $this->createRevision($model, [
                'restored_from' => $revision->id
            ], 'Restored from revision #' . $revision->id);
        });
    }

    public function pruneOldRevisions(int $keep = 10): int
    {
        return $this->executeTransaction(function() use ($keep) {
            $deleted = 0;
            $models = $this->model
                ->select('revisionable_type', 'revisionable_id')
                ->groupBy('revisionable_type', 'revisionable_id')
                ->get();

            foreach ($models as $model) {
                $revisions = $this->model
                    ->where('revisionable_type', $model->revisionable_type)
                    ->where('revisionable_id', $model->revisionable_id)
                    ->orderBy('created_at', 'desc')
                    ->get();

                if ($revisions->count() > $keep) {
                    $toDelete = $revisions->slice($keep);
                    $deleted += $toDelete->count();
                    $toDelete->each->delete();
                }
            }

            return $deleted;
        });
    }
}
