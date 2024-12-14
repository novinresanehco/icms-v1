<?php
namespace App\Core\Content;

class ContentRepository 
{
    private Model $model;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        Model $model,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(
            "content.{$id}",
            fn() => $this->model->find($id)
        );
    }

    public function store(array $data): Content
    {
        DB::beginTransaction();
        try {
            // Validate content data
            $validated = $this->validator->validate($data);

            // Store content
            $content = $this->model->create($validated);
            
            // Clear relevant cache
            $this->cache->tags(['content'])->flush();

            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to store content', 0, $e);  
        }
    }

    public function update(int $id, array $data): bool
    {
        DB::beginTransaction();
        try {
            // Validate update data
            $validated = $this->validator->validate($data);

            // Update content
            $updated = $this->model->findOrFail($id)
                                 ->update($validated);

            // Clear cache
            $this->cache->forget("content.{$id}");
            $this->cache->tags(['content'])->flush();

            DB::commit();
            return $updated;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to update content', 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            // Delete content
            $deleted = $this->model->findOrFail($id)->delete();

            // Clear cache
            $this->cache->forget("content.{$id}");
            $this->cache->tags(['content'])->flush();

            DB::commit();
            return $deleted;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to delete content', 0, $e);
        }
    }
}
