namespace App\Core\Content;

class ContentManager implements ContentManagerInterface
{
    private ContentRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private EventDispatcher $events;
    private AuditLogger $logger;

    public function __construct(
        ContentRepository $repository,
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        EventDispatcher $events,
        AuditLogger $logger
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->events = $events;
        $this->logger = $logger;
    }

    public function create(array $data, array $options = []): Content
    {
        return $this->security->executeCriticalOperation(new CreateContentOperation(
            $data,
            $options,
            function() use ($data, $options) {
                // Validate content data
                $validatedData = $this->validator->validate($data, ContentRules::create());
                
                // Create content record
                $content = $this->repository->create($validatedData);
                
                // Process media attachments if any
                if (!empty($options['media'])) {
                    $this->processMediaAttachments($content, $options['media']);
                }
                
                // Update cache
                $this->cache->forget($this->getCacheKey($content->id));
                
                // Dispatch events
                $this->events->dispatch(new ContentCreated($content));
                
                return $content;
            }
        ));
    }

    public function update(int $id, array $data, array $options = []): Content
    {
        return $this->security->executeCriticalOperation(new UpdateContentOperation(
            $id,
            $data,
            $options,
            function() use ($id, $data, $options) {
                // Get existing content
                $content = $this->repository->findOrFail($id);
                
                // Validate update data
                $validatedData = $this->validator->validate($data, ContentRules::update());
                
                // Update content
                $content = $this->repository->update($content, $validatedData);
                
                // Process media changes
                if (isset($options['media'])) {
                    $this->updateMediaAttachments($content, $options['media']);
                }
                
                // Clear cache
                $this->cache->forget($this->getCacheKey($id));
                
                // Dispatch events
                $this->events->dispatch(new ContentUpdated($content));
                
                return $content;
            }
        ));
    }

    public function delete(int $id, array $options = []): bool
    {
        return $this->security->executeCriticalOperation(new DeleteContentOperation(
            $id,
            $options,
            function() use ($id, $options) {
                // Get content
                $content = $this->repository->findOrFail($id);
                
                // Delete content
                $result = $this->repository->delete($content);
                
                // Clean up media
                if ($result && !($options['preserve_media'] ?? false)) {
                    $this->cleanupMediaAttachments($content);
                }
                
                // Clear cache
                $this->cache->forget($this->getCacheKey($id));
                
                // Dispatch events
                $this->events->dispatch(new ContentDeleted($content));
                
                return $result;
            }
        ));
    }

    public function publish(int $id, array $options = []): Content
    {
        return $this->security->executeCriticalOperation(new PublishContentOperation(
            $id,
            $options,
            function() use ($id, $options) {
                // Get content
                $content = $this->repository->findOrFail($id);
                
                // Validate publishable state
                if (!$content->isPublishable()) {
                    throw new ContentNotPublishableException();
                }
                
                // Update publish status
                $content = $this->repository->publish($content);
                
                // Clear cache
                $this->cache->forget($this->getCacheKey($id));
                
                // Dispatch events
                $this->events->dispatch(new ContentPublished($content));
                
                return $content;
            }
        ));
    }

    public function version(int $id): ContentVersion
    {
        return $this->security->executeCriticalOperation(new VersionContentOperation(
            $id,
            function() use ($id) {
                // Get content
                $content = $this->repository->findOrFail($id);
                
                // Create version
                $version = $this->repository->createVersion($content);
                
                // Dispatch events
                $this->events->dispatch(new ContentVersioned($content, $version));
                
                return $version;
            }
        ));
    }

    private function processMediaAttachments(Content $content, array $media): void
    {
        foreach ($media as $item) {
            $this->processMediaItem($content, $item);
        }
    }

    private function updateMediaAttachments(Content $content, array $media): void
    {
        // Remove old attachments
        $this->cleanupMediaAttachments($content);
        
        // Process new attachments
        $this->processMediaAttachments($content, $media);
    }

    private function processMediaItem(Content $content, array $item): void
    {
        $this->validator->validate($item, MediaRules::attachment());
        $this->repository->attachMedia($content, $item);
    }

    private function cleanupMediaAttachments(Content $content): void
    {
        $this->repository->detachMedia($content);
    }

    private function getCacheKey(int $id): string
    {
        return "content:{$id}";
    }
}
