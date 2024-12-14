<?php
namespace App\Core\CMS;

class ContentManager implements ContentInterface
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;

    public function store(array $data): Result
    {
        return $this->security->executeCriticalOperation(new StoreOperation(
            $data,
            $this->repository,
            $this->cache
        ));
    }

    public function retrieve(string $id): Content
    {
        return $this->cache->remember($id, function() use ($id) {
            return $this->security->executeCriticalOperation(
                new RetrieveOperation($id, $this->repository)
            );
        });
    }

    public function update(string $id, array $data): Result
    {
        return $this->security->executeCriticalOperation(new UpdateOperation(
            $id,
            $data,
            $this->repository,
            $this->cache
        ));
    }

    public function delete(string $id): Result
    {
        return $this->security->executeCriticalOperation(new DeleteOperation(
            $id,
            $this->repository,
            $this->cache
        ));
    }
}

class Repository implements RepositoryInterface
{
    private QueryBuilder $query;
    private ValidationService $validator;
    private array $rules;

    public function save(array $data): Model
    {
        $validated = $this->validator->validate($data, $this->rules);
        return $this->query->create($validated);
    }
    
    public function find(string $id): ?Model
    {
        return $this->query->findOrFail($id);
    }

    protected function validateData(array $data): array
    {
        return $this->validator->validate($data, $this->rules);
    }
}

class CacheManager implements CacheInterface 
{
    private Cache $store;
    private int $ttl;

    public function remember(string $key, callable $callback)
    {
        if ($cached = $this->get($key)) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value);
        return $value;
    }

    public function get(string $key)
    {
        return $this->store->get($key);
    }

    public function set(string $key, $value): void
    {
        $this->store->put($key, $value, $this->ttl);
    }
}
