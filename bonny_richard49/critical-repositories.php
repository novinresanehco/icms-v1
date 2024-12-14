<?php

namespace App\Core\Repositories;

abstract class CriticalRepository implements RepositoryInterface
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected CacheManager $cache;
    protected AuditLogger $audit;

    public function findOrFail($id)
    {
        $result = $this->find($id);
        
        if (!$result) {
            throw new NotFoundException("Resource not found: {$id}");
        }
        
        return $result;
    }

    public function find($id)
    {
        return $this->cache->remember($this->getCacheKey($id), function() use ($id) {
            return $this->performFind($id);
        });
    }

    public function create(array $data)
    {
        $this->validateData($data);
        
        DB::beginTransaction();
        
        try {
            $result = $this->performCreate($data);
            $this->cache->flush($this->getCacheTags());
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update($model, array $data)
    {
        $this->validateData($data);
        
        DB::beginTransaction();
        
        try {
            $result = $this->performUpdate($model, $data);
            $this->cache->flush($this->getCacheTags());
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete($model): bool
    {
        DB::beginTransaction();
        
        try {
            $result = $this->performDelete($model);
            $this->cache->flush($this->getCacheTags());
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    abstract protected function performFind($id);
    abstract protected function performCreate(array $data);
    abstract protected function performUpdate($model, array $data);
    abstract protected function performDelete($model): bool;
    abstract protected function getCacheKey($id): string;
    abstract protected function getCacheTags(): array;
    
    protected function validateData(array $data): void
    {
        if (!$this->validator->validate($data)) {
            throw new ValidationException('Invalid data');
        }
    }
}

class ContentRepository extends CriticalRepository
{
    protected function performFind($id)
    {
        return Content::find($id);
    }

    protected function performCreate(array $data)
    {
        return Content::create($data);
    }
    
    protected function performUpdate($model, array $data)
    {
        $model->update($data);
        return $model;
    }

    protected function performDelete($model): bool
    {
        return $model->delete();
    }

    public function publish(Content $content): bool
    {
        DB::beginTransaction();
        
        try {
            $content->status = true;
            $content->published_at = now();
            $result = $content->save();
            
            $this->cache->flush($this->getCacheTags());
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function getCacheKey($id): string
    {
        return "content.{$id}";
    }

    protected function getCacheTags(): array
    {
        return ['content'];
    }
}

class CategoryRepository extends CriticalRepository
{
    protected function performFind($id)
    {
        return Category::find($id);
    }

    protected function performCreate(array $data)
    {
        return Category::create($data);
    }

    protected function performUpdate($model, array $data)
    {
        $model->update($data);
        return $model;
    }

    protected function performDelete($model): bool
    {
        return $model->delete();
    }

    protected function getCacheKey($id): string
    {
        return "category.{$id}";
    }

    protected function getCacheTags(): array
    {
        return ['category'];
    }
}
