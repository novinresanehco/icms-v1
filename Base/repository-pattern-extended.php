<?php

namespace App\Core\Repositories;

interface RepositoryInterface
{
    public function find(int $id);
    public function findOrFail(int $id);
    public function all();
    public function paginate(int $perPage = 15);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
}

abstract class BaseRepository implements RepositoryInterface
{
    protected $model;
    protected $cache;
    protected array $with = [];
    protected int $cacheDuration = 3600;

    public function __construct()
    {
        $this->cache = app('cache');
    }

    protected function getCacheKey(string $method, $params = null): string
    {
        $key = strtolower(class_basename($this->model)) . ":{$method}";
        return $params ? "{$key}:" . md5(serialize($params)) : $key;
    }

    public function find(int $id)
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $id),
            $this->cacheDuration,
            fn() => $this->model->with($this->with)->find($id)
        );
    }

    public function findOrFail(int $id)
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $id),
            $this->cacheDuration,
            fn() => $this->model->with($this->with)->findOrFail($id)
        );
    }

    public function all()
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__),
            $this->cacheDuration,
            fn() => $this->model->with($this->with)->get()
        );
    }

    public function paginate(int $perPage = 15)
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, [$perPage, request()->query()]),
            $this->cacheDuration,
            fn() => $this->model->with($this->with)->paginate($perPage)
        );
    }

    public function create(array $data)
    {
        $model = $this->model->create($data);
        $this->clearCache();
        return $model;
    }

    public function update(int $id, array $data)
    {
        $model = $this->findOrFail($id);
        $model->update($data);
        $this->clearCache();
        return $model->fresh();
    }

    public function delete(int $id): bool
    {
        $result = $this->findOrFail($id)->delete();
        $this->clearCache();
        return $result;
    }

    protected function clearCache(): void
    {
        $pattern = strtolower(class_basename($this->model)) . ':*';
        $this->cache->tags($pattern)->flush();
    }
}

class ContentRepository extends BaseRepository
{
    protected array $with = ['categories', 'tags', 'author'];

    public function __construct(Content $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findBySlug(string $slug)
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $slug),
            $this->cacheDuration,
            fn() => $this->model->with($this->with)->where('slug', $slug)->first()
        );
    }

    public function getPublished()
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__),
            $this->cacheDuration,
            fn() => $this->model->with($this->with)
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function findByCategory(int $categoryId)
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $categoryId),
            $this->cacheDuration,
            fn() => $this->model->with($this->with)
                ->whereHas('categories', fn($q) => $q->where('id', $categoryId))
                ->get()
        );
    }
}

class UserRepository extends BaseRepository
{
    protected array $with = ['roles', 'permissions'];

    public function __construct(User $model) 
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findByEmail(string $email)
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $email),
            $this->cacheDuration,
            fn() => $this->model->with($this->with)->where('email', $email)->first()
        );
    }

    public function findByRole(string $role)
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $role),
            $this->cacheDuration,
            fn() => $this->model->with($this->with)
                ->whereHas('roles', fn($q) => $q->where('name', $role))
                ->get()
        );
    }
}
