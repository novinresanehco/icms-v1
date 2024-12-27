<?php

namespace App\Core\Content;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private CacheService $cache;
    private ContentRepository $repository;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function store(array $data): Content
    {
        DB::beginTransaction();
        try {
            $validated = $this->validator->validate($data);
            $protected = $this->security->encryptSensitiveData($validated);
            
            $content = $this->repository->create($protected);
            $this->audit->logContentChange('create', $content->id);
            $this->cache->forget(['content', $content->id]);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to store content', 0, $e);
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();
        try {
            $validated = $this->validator->validate($data);
            $protected = $this->security->encryptSensitiveData($validated);
            
            $content = $this->repository->update($id, $protected);
            $this->audit->logContentChange('update', $id);
            $this->cache->forget(['content', $id]);
            
            DB::commit();
            return $content;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to update content', 0, $e);
        }
    }

    public function retrieve(int $id): Content
    {
        return $this->cache->remember(['content', $id], function() use ($id) {
            $content = $this->repository->find($id);
            $decrypted = $this->security->decryptSensitiveData($content->toArray());
            return new Content($decrypted);
        });
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $deleted = $this->repository->delete($id);
            $this->audit->logContentChange('delete', $id);
            $this->cache->forget(['content', $id]);
            
            DB::commit();
            return $deleted;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to delete content', 0, $e);
        }
    }
}

class ContentRepository extends BaseRepository
{
    protected Model $model;
    protected ValidationService $validator;
    protected array $rules = [
        'title' => 'required|string',
        'content' => 'required|string',
        'status' => 'required|in:draft,published',
        'author_id' => 'required|exists:users,id'
    ];

    protected function validateData(array $data): array
    {
        return $this->validator->validate($data, $this->rules);
    }

    public function create(array $data): Content
    {
        $validated = $this->validateData($data);
        return $this->model->create($validated);
    }

    public function update(int $id, array $data): Content
    {
        $content = $this->findOrFail($id);
        $validated = $this->validateData($data);
        
        $content->update($validated);
        return $content->fresh();
    }

    public function find(int $id): ?Content
    {
        return $this->model->find($id);
    }

    public function delete(int $id): bool
    {
        return $this->model->destroy($id) > 0;
    }
}
