<?php

namespace App\Core\Content;

class ContentManager
{
    protected ContentRepository $repository;
    protected SecurityService $security;
    protected CacheManager $cache;
    protected ValidationService $validator;
    
    public function store(array $data, SecurityContext $context): Content
    {
        $operation = new StoreContentOperation($data, $context);
        
        if (!$this->security->validateOperation($operation)) {
            throw new ContentException('Content store operation failed security validation');
        }

        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'status' => 'required|in:draft,published'
        ]);

        DB::beginTransaction();
        try {
            $content = $this->repository->create($validated);
            $this->cache->forget("content:{$content->id}");
            DB::commit();
            return $content;
        } catch(\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to store content', 0, $e);
        }
    }

    public function retrieve(int $id, SecurityContext $context): ?Content
    {
        return $this->cache->remember("content:$id", 3600, function() use ($id, $context) {
            $operation = new RetrieveContentOperation($id, $context);
            
            if (!$this->security->validateOperation($operation)) {
                throw new ContentException('Content retrieve operation failed security validation');
            }

            return $this->repository->find($id);
        });
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        $operation = new UpdateContentOperation($id, $data, $context);
        
        if (!$this->security->validateOperation($operation)) {
            throw new ContentException('Content update operation failed security validation');
        }

        $validated = $this->validator->validate($data, [
            'title' => 'string|max:200',
            'body' => 'string',
            'status' => 'in:draft,published'
        ]);

        DB::beginTransaction();
        try {
            $content = $this->repository->update($id, $validated);
            $this->cache->forget("content:{$id}");
            DB::commit();
            return $content;
        } catch(\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to update content', 0, $e);
        }
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        $operation = new DeleteContentOperation($id, $context);
        
        if (!$this->security->validateOperation($operation)) {
            throw new ContentException('Content delete operation failed security validation');
        }

        DB::beginTransaction();
        try {
            $result = $this->repository->delete($id);
            $this->cache->forget("content:{$id}");
            DB::commit();
            return $result;
        } catch(\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to delete content', 0, $e);
        }
    }
}
