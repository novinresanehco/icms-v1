<?php

namespace App\Repositories;

use App\Models\Revision;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class RevisionRepository extends BaseRepository
{
    public function __construct(Revision $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function createRevision(Model $model, array $changes, string $reason = ''): Revision
    {
        $revision = $this->create([
            'revisionable_type' => get_class($model),
            'revisionable_id' => $model->getKey(),
            'user_id' => auth()->id(),
            'changes' => $changes,
            'reason' => $reason
        ]);

        $this->clearCache();
        return $revision;
    }

    public function findForModel(Model $model): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [get_class($model), $model->getKey()], function () use ($model) {
            return $this->model->where('revisionable_type', get_class($model))
                             ->where('revisionable_id', $model->getKey())
                             ->orderBy('created_at', 'desc')
                             ->get();
        });
    }

    public function findLatestRevision(Model $model): ?Revision
    {
        return $this->executeWithCache(__FUNCTION__, [get_class($model), $model->getKey()], function () use ($model) {
            return $this->model->where('revisionable_type', get_class($model))
                             ->where('revisionable_id', $model->getKey())
                             ->latest()
                             ->first();
        });
    }

    public function revertTo(Revision $revision): bool
    {
        $model = $revision->revisionable;
        if (!$model) {
            return false;
        }

        $model->update($revision->changes);
        
        $this->createRevision($model, $revision->changes, 'Reverted to revision #' . $revision->id);
        $this->clearCache();
        
        return true;
    }
}
