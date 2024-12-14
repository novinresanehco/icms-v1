namespace App\Core\CMS;

class ContentManager implements ContentManagementInterface
{
    private ContentRepository $content;
    private CategoryRepository $categories;
    private MediaRepository $media;
    private ValidationService $validator;
    private CacheManager $cache;
    private SecurityManager $security;

    public function __construct(
        ContentRepository $content,
        CategoryRepository $categories,
        MediaRepository $media,
        ValidationService $validator,
        CacheManager $cache,
        SecurityManager $security
    ) {
        $this->content = $content;
        $this->categories = $categories;
        $this->media = $media;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->security = $security;
    }

    public function create(array $data): Content
    {
        return $this->security->executeSecureOperation(function() use ($data) {
            DB::beginTransaction();
            
            try {
                $validated = $this->validator->validate($data, [
                    'title' => 'required|string|max:255',
                    'content' => 'required|string',
                    'category_id' => 'required|exists:categories,id',
                    'status' => 'required|in:draft,published',
                    'media_ids.*' => 'exists:media,id'
                ]);

                $content = $this->content->create($validated);

                if (!empty($validated['media_ids'])) {
                    $this->media->attachToContent($content->id, $validated['media_ids']);
                }

                $this->cache->tags(['content'])->flush();
                
                DB::commit();
                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to create content: ' . $e->getMessage(), 0, $e);
            }
        }, new SecurityContext('content.create'));
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeSecureOperation(function() use ($id, $data) {
            DB::beginTransaction();

            try {
                $validated = $this->validator->validate($data, [
                    'title' => 'string|max:255',
                    'content' => 'string',
                    'category_id' => 'exists:categories,id',
                    'status' => 'in:draft,published',
                    'media_ids.*' => 'exists:media,id'
                ]);

                $content = $this->content->update($id, $validated);

                if (isset($validated['media_ids'])) {
                    $this->media->syncWithContent($content->id, $validated['media_ids']);
                }

                $this->cache->tags(['content', "content-{$id}"])->flush();
                
                DB::commit();
                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to update content: ' . $e->getMessage(), 0, $e);
            }
        }, new SecurityContext('content.update', $id));
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();

            try {
                $this->media->detachFromContent($id);
                $result = $this->content->delete($id);
                $this->cache->tags(['content', "content-{$id}"])->flush();
                
                DB::commit();
                return $result;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to delete content: ' . $e->getMessage(), 0, $e);
            }
        }, new SecurityContext('content.delete', $id));
    }

    public function publish(int $id): Content
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();

            try {
                $content = $this->content->update($id, ['status' => 'published']);
                $this->cache->tags(['content', "content-{$id}"])->flush();
                
                DB::commit();
                return $content;

            } catch (\Exception $e) {
                DB::rollBack();
                throw new ContentException('Failed to publish content: ' . $e->getMessage(), 0, $e);
            }
        }, new SecurityContext('content.publish', $id));
    }

    public function find(int $id): ?Content
    {
        return $this->cache->tags(["content-{$id}"])->remember(
            "content.{$id}",
            3600,
            fn() => $this->content->find($id)
        );
    }

    public function list(array $filters = []): Collection
    {
        $cacheKey = 'content.list.' . md5(serialize($filters));
        
        return $this->cache->tags(['content'])->remember(
            $cacheKey,
            3600,
            fn() => $this->content->list($filters)
        );
    }

    public function attachMedia(int $contentId, array $mediaIds): void
    {
        $this->security->executeSecureOperation(function() use ($contentId, $mediaIds) {
            $this->media->attachToContent($contentId, $mediaIds);
            $this->cache->tags(["content-{$contentId}"])->flush();
        }, new SecurityContext('content.media.attach', $contentId));
    }
}
