<?php

namespace App\Core\Repository;

use App\Core\Security\SecurityContext;
use App\Core\Cache\CacheInterface;
use App\Core\Database\QueryBuilder;
use Illuminate\Support\Facades\DB;

abstract class BaseRepository implements RepositoryInterface
{
    protected QueryBuilder $query;
    protected CacheInterface $cache;
    protected string $table;
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $casts = [];

    public function __construct(QueryBuilder $query, CacheInterface $cache)
    {
        $this->query = $query;
        $this->cache = $cache;
    }

    public function find(int $id, array $relations = []): ?Model
    {
        $cacheKey = $this->getCacheKey('find', $id, $relations);

        return $this->cache->remember($cacheKey, function() use ($id, $relations) {
            return $this->query
                ->table($this->table)
                ->with($relations)
                ->where('id', $id)
                ->first();
        });
    }

    public function create(array $data): Model
    {
        DB::beginTransaction();
        
        try {
            $validated = $this->validateData($data);
            $model = $this->query->table($this->table)->create($validated);
            
            $this->invalidateListCache();
            $this->cache->forget($this->getCacheKey('find', $model->id));
            
            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Model
    {
        DB::beginTransaction();
        
        try {
            $validated = $this->validateData($data);
            
            $model = $this->query
                ->table($this->table)
                ->where('id', $id)
                ->update($validated);

            $this->invalidateListCache();
            $this->cache->forget($this->getCacheKey('find', $id));
            
            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        
        try {
            $deleted = $this->query
                ->table($this->table)
                ->where('id', $id)
                ->delete();

            if ($deleted) {
                $this->invalidateListCache();
                $this->cache->forget($this->getCacheKey('find', $id));
            }
            
            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function list(array $criteria = [], array $relations = []): Collection
    {
        $cacheKey = $this->getCacheKey('list', $criteria, $relations);

        return $this->cache->remember($cacheKey, function() use ($criteria, $relations) {
            $query = $this->query->table($this->table);

            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }

            if (!empty($relations)) {
                $query->with($relations);
            }

            return $query->get();
        });
    }

    protected function validateData(array $data): array
    {
        $validator = app(ValidationService::class);
        return $validator->validate($data, $this->getValidationRules());
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->table,
            $operation,
            md5(serialize($params))
        );
    }

    protected function invalidateListCache(): void
    {
        $pattern = sprintf('%s:list:*', $this->table);
        $this->cache->deletePattern($pattern);
    }

    abstract protected function getValidationRules(): array;
}

class ContentRepository extends BaseRepository
{
    protected string $table = 'contents';
    
    protected array $fillable = [
        'title',
        'content',
        'status',
        'user_id',
        'meta'
    ];

    protected array $hidden = [
        'deleted_at'
    ];

    protected array $casts = [
        'meta' => 'array',
        'published_at' => 'datetime'
    ];

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'user_id' => 'required|exists:users,id',
            'meta' => 'sometimes|array'
        ];
    }

    public function publish(int $id): bool
    {
        return $this->update($id, [
            'status' => 'published',
            'published_at' => now()
        ]);
    }

    public function createVersion(int $id): ContentVersion
    {
        $content = $this->find($id);
        
        return DB::transaction(function() use ($content) {
            return ContentVersion::create([
                'content_id' => $content->id,
                'title' => $content->title,
                'content' => $content->content,
                'meta' => $content->meta,
                'version' => $this->getNextVersionNumber($content->id)
            ]);
        });
    }

    private function getNextVersionNumber(int $contentId): int
    {
        return ContentVersion::where('content_id', $contentId)
            ->max('version') + 1;
    }
}
