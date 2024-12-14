<?php

namespace App\Core\Repository;

use App\Models\Meta;
use App\Core\Events\MetaEvents;
use App\Core\Exceptions\MetaRepositoryException;

class MetaRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Meta::class;
    }

    /**
     * Set meta data
     */
    public function setMeta(string $entityType, int $entityId, array $metadata): void
    {
        try {
            DB::beginTransaction();

            foreach ($metadata as $key => $value) {
                $this->model->updateOrCreate(
                    [
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                        'key' => $key
                    ],
                    [
                        'value' => $value,
                        'updated_at' => now(),
                        'updated_by' => auth()->id()
                    ]
                );
            }

            DB::commit();
            $this->clearCache();
            event(new MetaEvents\MetaUpdated($entityType, $entityId, $metadata));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MetaRepositoryException(
                "Failed to set metadata: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get meta data
     */
    public function getMeta(string $entityType, int $entityId, ?string $key = null): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("meta.{$entityType}.{$entityId}" . ($key ? ".{$key}" : "")),
            $this->cacheTime,
            function() use ($entityType, $entityId, $key) {
                $query = $this->model
                    ->where('entity_type', $entityType)
                    ->where('entity_id', $entityId);

                if ($key) {
                    $query->where('key', $key);
                }

                return $query->pluck('value', 'key')->toArray();
            }
        );
    }

    /**
     * Delete meta data
     */
    public function deleteMeta(string $entityType, int $entityId, ?string $key = null): void
    {
        try {
            $query = $this->model
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId);

            if ($key) {
                $query->where('key', $key);
            }

            $query->delete();
            $this->clearCache();
            event(new MetaEvents\MetaDeleted($entityType, $entityId, $key));

        } catch (\Exception $e) {
            throw new MetaRepositoryException(
                "Failed to delete metadata: {$e->getMessage()}"
            );
        }
    }

    /**
     * Search entities by meta
     */
    public function searchByMeta(string $entityType, array $criteria): Collection
    {
        $query = DB::table($entityType)
            ->distinct($entityType . '.id');

        foreach ($criteria as $key => $value) {
            $query->join("meta as meta_{$key}", function($join) use ($entityType, $key, $value) {
                $join->on($entityType . '.id', '=', "meta_{$key}.entity_id")
                    ->where("meta_{$key}.entity_type", '=', $entityType)
                    ->where("meta_{$key}.key", '=', $key)
                    ->where("meta_{$key}.value", '=', $value);
            });
        }

        return $query->get();
    }

    /**
     * Copy meta data
     */
    public function copyMeta(
        string $sourceType, 
        int $sourceId, 
        string $targetType, 
        int $targetId
    ): void {
        try {
            DB::beginTransaction();

            $metadata = $this->getMeta($sourceType, $sourceId);
            
            if (!empty($metadata)) {
                $this->setMeta($targetType, $targetId, $metadata);
            }

            DB::commit();
            event(new MetaEvents\MetaCopied($sourceType, $sourceId, $targetType, $targetId));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MetaRepositoryException(
                "Failed to copy metadata: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get all entities with specific meta
     */
    public function getEntitiesWithMeta(string $entityType, string $key, $value = null): Collection
    {
        $query = $this->model
            ->where('entity_type', $entityType)
            ->where('key', $key);

        if ($value !== null) {
            $query->where('value', $value);
        }

        return $query->get();
    }
}
