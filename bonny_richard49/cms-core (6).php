<?php
namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface 
{
    private ContentRepository $content;
    private MediaManager $media;
    private CacheManager $cache;
    private ValidationService $validator;

    public function create(array $data): Content 
    {
        DB::beginTransaction();
        try {
            $validated = $this->validator->validate($data, 'content.create');

            $content = $this->content->create($validated);

            if (isset($validated['media'])) {
                $this->media->attachToContent($content, $validated['media']);
            }

            $this->cache->tags(['content'])->flush();

            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to create content: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data): Content 
    {
        DB::beginTransaction();
        try {
            $content = $this->content->findOrFail($id);
            
            $validated = $this->validator->validate($data, 'content.update');
            
            $content = $this->content->update($id, $validated);

            if (isset($validated['media'])) {
                $this->media->syncWithContent($content, $validated['media']);
            }

            $this->cache->tags(['content', "content.{$id}"])->flush();

            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to update content: ' . $e->getMessage());
        }
    }

    public function publish(int $id): Content 
    {
        DB::beginTransaction();
        try {
            $content = $this->content->findOrFail($id);
            
            if (!$content->isValid()) {
                throw new InvalidContentException();
            }

            $content->publish();
            
            $this->cache->tags(['content', "content.{$id}"])->flush();

            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to publish content: ' . $e->getMessage());
        }
    }

    public function delete(int $id): void 
    {
        DB::beginTransaction();
        try {
            $content = $this->content->findOrFail($id);
            
            $this->media->detachFromContent($content);
            $this->content->delete($id);
            
            $this->cache->tags(['content', "content.{$id}"])->flush();

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to delete content: ' . $e->getMessage());
        }
    }

    public function get(int $id): ?Content
    {
        return $this->cache->tags(["content.{$id}"])->remember(
            "content.{$id}",
            3600,
            fn() => $this->content->find($id)
        );
    }

    public function list(array $criteria = []): Collection
    {
        return $this->cache->tags(['content'])->remember(
            'content.list.' . md5(serialize($criteria)),
            3600,
            fn() => $this->content->findAll($criteria)
        );
    }
}
