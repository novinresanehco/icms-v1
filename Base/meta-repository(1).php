<?php

namespace App\Core\Repositories;

use App\Models\Meta;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class MetaRepository extends AdvancedRepository
{
    protected $model = Meta::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getForModel($model): Collection
    {
        return $this->executeQuery(function() use ($model) {
            return $this->cache->remember(
                "meta.{$model->getMorphClass()}.{$model->id}",
                function() use ($model) {
                    return $this->model
                        ->where('metable_type', get_class($model))
                        ->where('metable_id', $model->id)
                        ->get();
                }
            );
        });
    }

    public function setMeta($model, string $key, $value): void
    {
        $this->executeTransaction(function() use ($model, $key, $value) {
            $this->model->updateOrCreate(
                [
                    'metable_type' => get_class($model),
                    'metable_id' => $model->id,
                    'key' => $key
                ],
                ['value' => $value]
            );

            $this->cache->forget("meta.{$model->getMorphClass()}.{$model->id}");
        });
    }

    public function setMany($model, array $metadata): void
    {
        $this->executeTransaction(function() use ($model, $metadata) {
            foreach ($metadata as $key => $value) {
                $this->setMeta($model, $key, $value);
            }
        });
    }

    public function deleteMeta($model, string $key): void
    {
        $this->executeTransaction(function() use ($model, $key) {
            $this->model
                ->where('metable_type', get_class($model))
                ->where('metable_id', $model->id)
                ->where('key', $key)
                ->delete();

            $this->cache->forget("meta.{$model->getMorphClass()}.{$model->id}");
        });
    }
}
