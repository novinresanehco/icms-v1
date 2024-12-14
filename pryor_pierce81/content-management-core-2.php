namespace App\Core\Content;

class ContentManager implements ContentManagementInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private EventDispatcher $events;

    public function __construct(
        SecurityManager $security,
        Repository $repository,
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

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->repository),
            SecurityContext::fromRequest()
        );
    }

    public function update(int $id, array $data): Content
    {
        $operation = new UpdateContentOperation(
            $id, 
            $data,
            $this->repository,
            $this->cache
        );

        return $this->security->executeCriticalOperation(
            $operation,
            SecurityContext::fromRequest()
        );
    }

    public function delete(int $id): bool
    {
        $operation = new DeleteContentOperation(
            $id,
            $this->repository,
            $this->cache
        );

        return $this->security->executeCriticalOperation(
            $operation,
            SecurityContext::fromRequest()
        );
    }

    public function publish(int $id): bool
    {
        $operation = new PublishContentOperation(
            $id,
            $this->repository,
            $this->cache,
            $this->events
        );

        return $this->security->executeCriticalOperation(
            $operation,
            SecurityContext::fromRequest()
        );
    }

    public function version(int $id): ContentVersion
    {
        $operation = new VersionContentOperation(
            $id,
            $this->repository,
            $this->events
        );

        return $this->security->executeCriticalOperation(
            $operation,
            SecurityContext::fromRequest()
        );
    }

    public function cacheContent(Content $content): void
    {
        $cacheKey = $this->generateCacheKey($content);
        $this->cache->set($cacheKey, $content, 3600);
    }

    private function generateCacheKey(Content $content): string 
    {
        return sprintf(
            'content.%s.%s',
            $content->getId(),
            $content->getUpdatedAt()->getTimestamp()
        );
    }

    private function validateContent(array $data): array
    {
        $rules = [
            'title' => 'required|max:255',
            'content' => 'required',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|exists:users,id',
            'meta' => 'array'
        ];

        return $this->validator->validate($data, $rules);
    }

    private function clearContentCache(int $id): void
    {
        $this->cache->forget("content.{$id}.*");
        $this->cache->forget('content.list');
    }

    private function dispatchContentEvent(string $event, Content $content): void
    {
        $this->events->dispatch(
            $event,
            ['content' => $content]
        );
    }

    public function attachMedia(int $contentId, array $mediaIds): void
    {
        $operation = new AttachMediaOperation(
            $contentId,
            $mediaIds,
            $this->repository
        );

        $this->security->executeCriticalOperation(
            $operation,
            SecurityContext::fromRequest()
        );
    }

    public function setPermissions(int $contentId, array $permissions): void
    {
        $operation = new SetPermissionsOperation(
            $contentId,
            $permissions,
            $this->repository
        );

        $this->security->executeCriticalOperation(
            $operation,
            SecurityContext::fromRequest()
        );
    }

    public function renderContent(int $contentId): string
    {
        return $this->security->executeCriticalOperation(
            new RenderContentOperation(
                $contentId,
                $this->repository,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }
}
