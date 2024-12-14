<?php

namespace App\Core\Repository;

abstract class BaseRepository
{
    protected Model $model;
    protected CacheManager $cache;
    protected SecurityService $security;
    protected ValidationService $validator;

    public function find(int $id, SecurityContext $context): ?Model 
    {
        $this->security->validateAccess($context);

        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            3600,
            fn() => $this->model->find($id)
        );
    }

    public function create(array $data, SecurityContext $context): Model
    {
        $this->security->validateAccess($context);
        $validated = $this->validator->validate($data);

        DB::beginTransaction();
        try {
            $model = $this->model->create($validated);
            $this->cache->tags($this->getCacheTags())->flush();
            DB::commit();
            return $model;
        } catch(\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Creation failed', 0, $e);
        }
    }

    public function update(int $id, array $data, SecurityContext $context): Model
    {
        $this->security->validateAccess($context);
        $validated = $this->validator->validate($data);
        
        DB::beginTransaction();
        try {
            $model = $this->model->findOrFail($id);
            $model->update($validated);
            $this->cache->tags($this->getCacheTags())->flush();
            DB::commit();
            return $model;
        } catch(\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Update failed', 0, $e);
        }
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        $this->security->validateAccess($context);

        DB::beginTransaction();
        try {
            $result = $this->model->findOrFail($id)->delete();
            $this->cache->tags($this->getCacheTags())->flush();
            DB::commit();
            return $result;
        } catch(\Exception $e) {
            DB::rollBack();
            throw new RepositoryException('Deletion failed', 0, $e);
        }
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            implode(':', $params)
        );
    }

    protected function getCacheTags(): array
    {
        return [$this->model->getTable()];
    }
}
