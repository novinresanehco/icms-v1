namespace App\Core;

class CoreCMS
{
    private SecurityManager $security;
    private ContentRepository $content;
    private MediaManager $media;
    private CacheManager $cache;
    private CategoryRepository $categories;

    public function __construct(
        SecurityManager $security,
        ContentRepository $content,
        MediaManager $media, 
        CacheManager $cache,
        CategoryRepository $categories
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->media = $media;
        $this->cache = $cache;
        $this->categories = $categories;
    }

    public function handleContentOperation(Request $request): Response
    {
        DB::beginTransaction();
        try {
            $this->security->validateRequest($request);
            $result = match($request->getOperation()) {
                'create' => $this->createContent($request->getData()),
                'update' => $this->updateContent($request->getId(), $request->getData()),
                'delete' => $this->deleteContent($request->getId()),
                'publish' => $this->publishContent($request->getId()),
                default => throw new InvalidOperationException()
            };
            DB::commit();
            return new Response($result);
        } catch (Exception $e) {
            DB::rollBack();
            $this->handleFailure($e);
            throw $e;
        }
    }

    private function createContent(array $data): Content
    {
        $content = $this->content->create($this->validateContentData($data));
        $this->handleMediaAttachments($content, $data['media'] ?? []);
        $this->assignCategories($content, $data['categories'] ?? []);
        $this->cache->invalidateContentCache();
        return $content;
    }

    private function updateContent(int $id, array $data): Content
    {
        $content = $this->content->update($id, $this->validateContentData($data));
        $this->handleMediaAttachments($content, $data['media'] ?? []);
        $this->assignCategories($content, $data['categories'] ?? []);
        $this->cache->invalidateContentCache();
        return $content;
    }

    private function deleteContent(int $id): bool
    {
        $content = $this->content->findOrFail($id);
        $this->media->detachFromContent($content->id);
        $this->categories->detachFromContent($content->id);
        $result = $this->content->delete($id);
        $this->cache->invalidateContentCache();
        return $result;
    }

    private function publishContent(int $id): Content
    {
        $content = $this->content->findOrFail($id);
        $content->publish();
        $this->cache->invalidateContentCache();
        return $content;
    }

    private function validateContentData(array $data): array
    {
        $validator = Validator::make($data, [
            'title' => 'required|max:200',
            'body' => 'required',
            'status' => 'required|in:draft,published',
            'media.*' => 'numeric|exists:media,id',
            'categories.*' => 'numeric|exists:categories,id'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    private function handleMediaAttachments(Content $content, array $mediaIds): void
    {
        $this->media->syncWithContent($content->id, $mediaIds);
    }

    private function assignCategories(Content $content, array $categoryIds): void
    {
        $this->categories->syncWithContent($content->id, $categoryIds);
    }

    private function handleFailure(Exception $e): void
    {
        Log::error('Content operation failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class MediaManager
{
    private MediaRepository $repository;
    private DiskManager $disk;

    public function handleMedia(UploadedFile $file): Media
    {
        $path = $this->disk->store($file, 'media');
        return $this->repository->create([
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize()
        ]);
    }

    public function syncWithContent(int $contentId, array $mediaIds): void
    {
        $this->repository->syncWithContent($contentId, $mediaIds);
    }

    public function detachFromContent(int $contentId): void
    {
        $this->repository->detachFromContent($contentId);
    }
}

class CategoryManager
{
    private CategoryRepository $repository;
    private CacheManager $cache;

    public function handleCategory(array $data): Category
    {
        $category = $this->repository->create($this->validateCategoryData($data));
        $this->cache->invalidateCategoryCache();
        return $category;
    }

    private function validateCategoryData(array $data): array
    {
        $validator = Validator::make($data, [
            'name' => 'required|max:100|unique:categories',
            'slug' => 'required|max:100|unique:categories',
            'parent_id' => 'nullable|exists:categories,id'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }
}

trait CacheableRepository
{
    protected function remember(string $key, Closure $callback)
    {
        return Cache::remember($key, $this->getCacheDuration(), $callback);
    }

    protected function invalidateCache(string $tag): void
    {
        Cache::tags($tag)->flush();
    }

    protected function getCacheDuration(): int
    {
        return config('cms.cache.duration', 3600);
    }
}

interface ContentRepository
{
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
    public function findOrFail(int $id): Content;
}

interface CategoryRepository
{
    public function create(array $data): Category;
    public function syncWithContent(int $contentId, array $categoryIds): void;
    public function detachFromContent(int $contentId): void;
}
