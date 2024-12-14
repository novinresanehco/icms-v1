namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private Repository $repository;
    private EventDispatcher $events;
    private AuditLogger $logger;

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, function() use ($data) {
                $validated = $this->validator->validate($data, [
                    'title' => 'required|max:200',
                    'content' => 'required',
                    'status' => 'required|in:draft,published',
                    'author_id' => 'required|exists:users,id'
                ]);

                $content = $this->repository->create($validated);
                
                $this->cache->tags(['content'])->invalidate();
                
                $this->events->dispatch(new ContentCreated($content));
                
                $this->logger->log('content.created', [
                    'content_id' => $content->id,
                    'author_id' => $validated['author_id']
                ]);

                return $content;
            })
        );
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, function() use ($id, $data) {
                $content = $this->repository->findOrFail($id);
                
                $validated = $this->validator->validate($data, [
                    'title' => 'sometimes|max:200',
                    'content' => 'sometimes',
                    'status' => 'sometimes|in:draft,published'
                ]);

                $content->update($validated);
                
                $this->cache->tags(['content'])->invalidate();
                
                $this->events->dispatch(new ContentUpdated($content));
                
                $this->logger->log('content.updated', [
                    'content_id' => $content->id, 
                    'changes' => $validated
                ]);

                return $content;
            })
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                $content->delete();
                
                $this->cache->tags(['content'])->invalidate();
                
                $this->events->dispatch(new ContentDeleted($content));
                
                $this->logger->log('content.deleted', [
                    'content_id' => $id
                ]);

                return true;
            })
        );
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, function() use ($id) {
                $content = $this->repository->findOrFail($id);
                
                if ($content->status !== 'draft') {
                    throw new InvalidStateException('Content must be in draft state');
                }

                $content->update(['status' => 'published']);
                
                $this->cache->tags(['content'])->invalidate();
                
                $this->events->dispatch(new ContentPublished($content));
                
                $this->logger->log('content.published', [
                    'content_id' => $id
                ]);

                return true;
            })
        );
    }

    public function find(int $id): ?Content
    {
        return $this->cache->tags(['content'])->remember(
            "content.$id",
            3600,
            fn() => $this->repository->find($id)
        );
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->cache->tags(['content'])->remember(
            "content.slug.$slug",
            3600,
            fn() => $this->repository->findBySlug($slug)
        );
    }

    public function getVersion(int $id, int $version): ?ContentVersion
    {
        return $this->cache->tags(['content', 'versions'])->remember(
            "content.$id.version.$version",
            3600,
            fn() => $this->repository->getVersion($id, $version)
        );
    }

    public function restore(int $id, int $version): Content
    {
        return $this->security->executeCriticalOperation(
            new RestoreContentOperation($id, $version, function() use ($id, $version) {
                $content = $this->repository->findOrFail($id);
                $versionData = $this->repository->getVersion($id, $version);

                if (!$versionData) {
                    throw new VersionNotFoundException();
                }

                $content->update($versionData->getData());
                
                $this->cache->tags(['content', 'versions'])->invalidate();
                
                $this->events->dispatch(new ContentRestored($content, $version));
                
                $this->logger->log('content.restored', [
                    'content_id' => $id,
                    'version' => $version
                ]);

                return $content;
            })
        );
    }

    public function search(array $criteria): Collection
    {
        $cacheKey = 'content.search.' . md5(serialize($criteria));
        
        return $this->cache->tags(['content'])->remember(
            $cacheKey,
            1800,
            fn() => $this->repository->search($criteria)
        );
    }
}
