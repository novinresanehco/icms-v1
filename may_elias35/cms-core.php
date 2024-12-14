namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private EventDispatcher $events;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        CacheManager $cache,
        ValidationService $validator,
        EventDispatcher $events
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function createContent(array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreate($data),
            new SecurityContext('content.create', $data)
        );
    }

    private function executeCreate(array $data): Content 
    {
        // Validate content data
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ]);

        // Create with transaction and cache management
        DB::beginTransaction();
        try {
            // Store content
            $content = $this->repository->create($validated);
            
            // Process media attachments if any
            if (isset($validated['media'])) {
                $this->processMedia($content, $validated['media']);
            }

            // Handle categories
            if (isset($validated['categories'])) {
                $this->processCategories($content, $validated['categories']);
            }

            // Clear relevant caches
            $this->cache->tags(['content'])->flush();

            // Dispatch events
            $this->events->dispatch(new ContentCreated($content));

            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to create content: ' . $e->getMessage());
        }
    }

    public function updateContent(int $id, array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdate($id, $data),
            new SecurityContext('content.update', ['id' => $id, 'data' => $data])
        );
    }

    private function executeUpdate(int $id, array $data): Content 
    {
        $content = $this->repository->findOrFail($id);
        
        // Validate update data
        $validated = $this->validator->validate($data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published'
        ]);

        DB::beginTransaction();
        try {
            // Update content
            $content = $this->repository->update($id, $validated);
            
            // Update associations
            if (isset($validated['media'])) {
                $this->processMedia($content, $validated['media']);
            }

            if (isset($validated['categories'])) {
                $this->processCategories($content, $validated['categories']);
            }

            // Clear caches
            $this->cache->tags(['content', "content-{$id}"])->flush();

            // Dispatch events
            $this->events->dispatch(new ContentUpdated($content));

            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to update content: ' . $e->getMessage());
        }
    }

    public function deleteContent(int $id): bool 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDelete($id),
            new SecurityContext('content.delete', ['id' => $id])
        );
    }

    private function executeDelete(int $id): bool 
    {
        DB::beginTransaction();
        try {
            $content = $this->repository->findOrFail($id);
            
            // Remove associations
            $content->media()->detach();
            $content->categories()->detach();
            
            // Delete content
            $this->repository->delete($id);
            
            // Clear caches
            $this->cache->tags(['content', "content-{$id}"])->flush();
            
            // Dispatch events
            $this->events->dispatch(new ContentDeleted($id));

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Failed to delete content: ' . $e->getMessage());
        }
    }

    public function getContent(int $id): Content 
    {
        return $this->cache->tags("content-{$id}")->remember(
            "content.{$id}",
            3600,
            fn() => $this->repository->findOrFail($id)
        );
    }

    private function processMedia(Content $content, array $media): void 
    {
        $content->media()->sync($media);
    }

    private function processCategories(Content $content, array $categories): void 
    {
        $content->categories()->sync($categories);
    }
}
