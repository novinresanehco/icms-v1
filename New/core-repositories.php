<?php

namespace App\Core\Repositories;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected array $rules = [];

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->model->find($id)
        );
    }

    public function store(array $data): Model
    {
        $validated = $this->validator->validate($data, $this->rules);
        
        return DB::transaction(function() use ($validated) {
            $model = $this->model->create($validated);
            $this->cache->invalidate($this->getCacheKey('find', $model->id));
            return $model;
        });
    }

    public function update(int $id, array $data): Model
    {
        $validated = $this->validator->validate($data, $this->rules);
        
        return DB::transaction(function() use ($id, $validated) {
            $model = $this->model->findOrFail($id);
            $model->update($validated);
            $this->cache->invalidate($this->getCacheKey('find', $id));
            return $model;
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $result = $this->model->destroy($id);
            $this->cache->invalidate($this->getCacheKey('find', $id));
            return $result;
        });
    }

    abstract protected function getCacheKey(string $operation, ...$params): string;
}

class ContentRepository extends BaseRepository
{
    protected array $rules = [
        'title' => 'required|string|max:255',
        'content' => 'required|string',
        'status' => 'required|in:draft,published',
        'user_id' => 'required|exists:users,id'
    ];

    protected function getCacheKey(string $operation, ...$params): string
    {
        return "content:{$operation}:" . implode(':', $params);
    }
}

class UserRepository extends BaseRepository
{
    protected array $rules = [
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        'password' => 'required|string|min:8',
        'role_id' => 'required|exists:roles,id'
    ];

    protected function getCacheKey(string $operation, ...$params): string
    {
        return "user:{$operation}:" . implode(':', $params);
    }
}

class MediaRepository extends BaseRepository
{
    protected array $rules = [
        'path' => 'required|string',
        'type' => 'required|string',
        'size' => 'required|integer',
        'user_id' => 'required|exists:users,id',
        'metadata' => 'nullable|array'
    ];

    protected function getCacheKey(string $operation, ...$params): string
    {
        return "media:{$operation}:" . implode(':', $params);
    }
}

interface RepositoryInterface
{
    public function find(int $id): ?Model;
    public function store(array $data): Model;
    public function update(int $id, array $data): Model;
    public function delete(int $id): bool;
}
