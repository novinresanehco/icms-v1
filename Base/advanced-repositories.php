<?php

namespace App\Core\Repositories;

use App\Core\Contracts\VersionableInterface;
use App\Core\Contracts\AuditableInterface;
use App\Core\Events\ContentVersioned;
use App\Core\Events\ContentRestored;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\VersionException;

/**
 * Trait for handling content versioning in repositories
 */
trait HasVersioning
{
    protected function createVersion(Model $model, array $data = [], string $reason = ''): void
    {
        if (!$model instanceof VersionableInterface) {
            throw new VersionException('Model must implement VersionableInterface');
        }

        DB::transaction(function () use ($model, $data, $reason) {
            $version = $model->versions()->create([
                'content' => json_encode($data ?: $model->getAttributes()),
                'user_id' => auth()->id(),
                'reason' => $reason,
                'version' => $model->versions()->count() + 1,
                'hash' => $this->generateVersionHash($data ?: $model->getAttributes()),
                'created_at' => now()
            ]);

            event(new ContentVersioned($model, $version));
        });
    }

    protected function restoreVersion(Model $model, int $versionNumber): bool
    {
        if (!$model instanceof VersionableInterface) {
            throw new VersionException('Model must implement VersionableInterface');
        }

        return DB::transaction(function () use ($model, $versionNumber) {
            $version = $model->versions()
                ->where('version', $versionNumber)
                ->firstOrFail();

            $data = json_decode($version->content, true);
            $model->update($data);

            $this->createVersion($model, null, "Restored to version {$versionNumber}");
            event(new ContentRestored($model, $version));

            return true;
        });
    }

    protected function generateVersionHash(array $data): string
    {
        ksort($data);
        return hash('sha256', json_encode($data));
    }
}

/**
 * Trait for handling advanced caching strategies in repositories
 */
trait AdvancedCaching
{
    protected function getCacheKey(string $method, array $args = []): string
    {
        $modelName = class_basename($this->model);
        $argsHash = md5(serialize($args));
        return "repository.{$modelName}.{$method}.{$argsHash}";
    }

    protected function getCacheTags(): array
    {
        return [
            class_basename($this->model),
            'repository'
        ];
    }

    protected function cacheQuery(callable $callback, int $ttl = null)
    {
        $key = $this->getCacheKey(debug_backtrace()[1]['function'], func_get_args());
        $ttl = $ttl ?? config('cache.repositories.ttl', 3600);

        return cache()->tags($this->getCacheTags())->remember($key, $ttl, $callback);
    }

    protected function invalidateCache(): void
    {
        cache()->tags($this->getCacheTags())->flush();
    }
}

/**
 * Enhanced base repository with advanced features
 */
abstract class AdvancedRepository extends BaseRepository
{
    use HasVersioning, AdvancedCaching;

    protected bool $enableVersioning = false;
    protected array $versionableFields = [];
    protected int $maxVersions = 50;

    public function create(array $data): Model
    {
        $model = parent::create($data);

        if ($this->enableVersioning) {
            $this->createVersion($model, $data, 'Initial version');
        }

        return $model;
    }

    public function update(Model $model, array $data): bool
    {
        if ($this->enableVersioning && $this->shouldCreateVersion($model, $data)) {
            $this->createVersion($model, $model->getAttributes(), 'Pre-update version');
        }

        $updated = parent::update($model, $data);

        $this->cleanupOldVersions($model);
        
        return $updated;
    }

    protected function shouldCreateVersion(Model $model, array $newData): bool
    {
        if (empty($this->versionableFields)) {
            return true;
        }

        $existingData = $model->only($this->versionableFields);
        $newData = array_intersect_key($newData, array_flip($this->versionableFields));

        return count(array_diff_assoc($newData, $existingData)) > 0;
    }

    protected function cleanupOldVersions(Model $model): void
    {
        if (!$model instanceof VersionableInterface) {
            return;
        }

        $versions = $model->versions()
            ->orderBy('version', 'desc')
            ->get();

        if ($versions->count() > $this->maxVersions) {
            $versionsToDelete = $versions->slice($this->maxVersions);
            $model->versions()
                ->whereIn('id', $versionsToDelete->pluck('id'))
                ->delete();
        }
    }

    public function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    public function commit(): void
    {
        DB::commit();
        $this->invalidateCache();
    }

    public function rollback(): void
    {
        DB::rollBack();
    }
}

/**
 * Example implementation of versioned content repository
 */
class VersionedContentRepository extends AdvancedRepository
{
    protected bool $enableVersioning = true;
    protected array $versionableFields = ['title', 'content', 'status', 'category_id'];
    
    protected function model(): string
    {
        return Content::class;
    }

    public function findWithVersions(int $id): ?Model
    {
        return $this->cacheQuery(fn() => 
            $this->applyCriteria()
                ->with(['versions' => fn($query) => 
                    $query->orderBy('version', 'desc')
                ])
                ->find($id)
        );
    }

    public function compareVersions(Model $model, int $versionA, int $versionB): array
    {
        $versions = $model->versions()
            ->whereIn('version', [$versionA, $versionB])
            ->get()
            ->keyBy('version');

        $dataA = json_decode($versions[$versionA]->content, true);
        $dataB = json_decode($versions[$versionB]->content, true);

        return [
            'additions' => array_diff_assoc($dataB, $dataA),
            'deletions' => array_diff_assoc($dataA, $dataB),
            'versions' => $versions
        ];
    }
}
