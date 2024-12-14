<?php

namespace App\Core\Content;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{DB, Cache};

class ContentManager
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function create(array $data): Content
    {
        return $this->security->protectedExecute(function() use ($data) {
            $validated = $this->validator->validate($data);
            
            DB::beginTransaction();
            try {
                $content = $this->repository->create($validated);
                $this->cache->invalidate(['content']);
                DB::commit();
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->protectedExecute(function() use ($id, $data) {
            $validated = $this->validator->validate($data);
            
            DB::beginTransaction();
            try {
                $content = $this->repository->update($id, $validated);
                $this->cache->invalidate(['content', "content.$id"]);
                DB::commit();
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    public function delete(int $id): bool
    {
        return $this->security->protectedExecute(function() use ($id) {
            DB::beginTransaction();
            try {
                $result = $this->repository->delete($id);
                $this->cache->invalidate(['content', "content.$id"]);
                DB::commit();
                return $result;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    public function get(int $id): ?Content
    {
        return $this->cache->remember(
            "content.$id",
            fn() => $this->repository->find($id)
        );
    }

    public function list(array $criteria = []): array
    {
        return $this->cache->remember(
            $this->getCacheKey($criteria),
            fn() => $this->repository->findBy($criteria)
        );
    }

    private function getCacheKey(array $criteria): string
    {
        return 'content.list.' . md5(serialize($criteria));
    }
}

class Content
{
    private int $id;
    private string $type;
    private array $data;
    private ?int $userId;
    private array $meta;

    public function __construct(array $attributes)
    {
        $this->id = $attributes['id'];
        $this->type = $attributes['type'];
        $this->data = $attributes['data'];
        $this->userId = $attributes['user_id'] ?? null;
        $this->meta = $attributes['meta'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
            'user_id' => $this->userId,
            'meta' => $this->meta
        ];
    }
}

class ContentRepository
{
    private DB $db;

    public function create(array $data): Content
    {
        $id = DB::table('contents')->insertGetId($data);
        return new Content(['id' => $id] + $data);
    }

    public function update(int $id, array $data): Content
    {
        DB::table('contents')->where('id', $id)->update($data);
        return new Content(['id' => $id] + $data);
    }

    public function delete(int $id): bool
    {
        return DB::table('contents')->where('id', $id)->delete() > 0;
    }

    public function find(int $id): ?Content
    {
        $data = DB::table('contents')->find($id);
        return $data ? new Content((array)$data) : null;
    }

    public function findBy(array $criteria): array
    {
        $query = DB::table('contents');
        
        foreach ($criteria as $key => $value) {
            $query->where($key, $value);
        }

        return $query->get()->map(fn($data) => new Content((array)$data))->all();
    }
}