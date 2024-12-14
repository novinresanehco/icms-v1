<?php

namespace App\Repositories;

use App\Models\Meta;
use App\Repositories\Contracts\MetaRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MetaRepository implements MetaRepositoryInterface
{
    protected Meta $model;
    protected int $cacheTTL = 3600;

    public function __construct(Meta $model)
    {
        $this->model = $model;
    }

    public function create(string $metaable_type, int $metaable_id, array $data): ?int
    {
        try {
            DB::beginTransaction();

            $meta = $this->model->create([
                'metaable_type' => $metaable_type,
                'metaable_id' => $metaable_id,
                'key' => $data['key'],
                'value' => $data['value'],
                'type' => $data['type'] ?? 'string',
                'group' => $data['group'] ?? 'default',
            ]);

            $this->clearMetaCache($metaable_type, $metaable_id);
            DB::commit();

            return $meta->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create meta: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            DB::beginTransaction();

            $meta = $this->model->findOrFail($id);
            
            $meta->update([
                'value' => $data['value'],
                'type' => $data['type'] ?? $meta->type,
                'group' => $data['group'] ?? $meta->group,
            ]);

            $this->clearMetaCache($meta->metaable_type, $meta->metaable_id);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update meta: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $meta = $this->model->findOrFail($id);
            $metaableType = $meta->metaable_type;
            $metaableId = $meta->metaable_id;
            
            $meta->delete();

            $this->clearMetaCache($metaableType, $metaableId);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete meta: ' . $e->getMessage());
            return false;
        }
    }

    public function getForModel(string $metaable_type, int $metaable_id): Collection
    {
        try {
            return Cache::remember(
                "meta.{$metaable_type}.{$metaable_id}",
                $this->cacheTTL,
                fn() => $this->model->where('metaable_type', $metaable_type)
                    ->where('metaable_id', $metaable_id)
                    ->get()
                    ->keyBy('key')
            );
        } catch (\Exception $e) {
            Log::error('Failed to get meta for model: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getByKey(string $metaable_type, int $metaable_id, string $key): ?array
    {
        try {
            return Cache::remember(
                "meta.{$metaable_type}.{$metaable_id}.{$key}",
                $this->cacheTTL,
                fn() => $this->model->where('metaable_type', $metaable_type)
                    ->where('metaable_id', $metaable_id)
                    ->where('key', $key)
                    ->first()
                    ?->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get meta by key: ' . $e->getMessage());
            return null;
        }
    }

    public function updateOrCreate(string $metaable_type, int $metaable_id, string $key, $value, string $type = 'string'): bool
    {
        try {
            DB::beginTransaction();

            $this->model->updateOrCreate(
                [
                    'metaable_type' => $metaable_type,
                    'metaable_id' => $metaable_id,
                    'key' => $key,
                ],
                [
                    'value' => $value,
                    'type' => $type,
                ]
            );

            $this->clearMetaCache($metaable_type, $metaable_id);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update or create meta: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteForModel(string $metaable_type, int $metaable_id): bool
    {
        try {
            DB::beginTransaction();

            $this->model->where('metaable_type', $metaable_type)
                ->where('metaable_id', $metaable_id)
                ->delete();

            $this->clearMetaCache($metaable_type, $metaable_id);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete meta for model: ' . $e->getMessage());
            return false;
        }
    }

    protected function clearMetaCache(string $metaable_type, int $metaable_id): void
    {
        Cache::tags(["meta.{$metaable_type}.{$metaable_id}"])->flush();
    }
}
