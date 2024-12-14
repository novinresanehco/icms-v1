<?php

namespace App\Core\CMS;

class ContentManager implements ContentManagementInterface
{
    private Repository $repository;
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private EventDispatcher $events;

    public function __construct(
        Repository $repository,
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        EventDispatcher $events
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(new CreateContentOperation(
            $data,
            $this->repository,
            $this->validator,
            $this->events
        ));
    }

    public function update(int $id, array $data): Content
    {
        $operation = new UpdateContentOperation(
            $id,
            $data,
            $this->repository,
            $this->validator,
            $this->events
        );
        
        $content = $this->security->executeCriticalOperation($operation);
        $this->cache->invalidate($this->getCacheKey($id));
        
        return $content;
    }

    public function delete(int $id): bool
    {
        $result = $this->security->executeCriticalOperation(new DeleteContentOperation(
            $id,
            $this->repository,
            $this->events
        ));
        
        if ($result) {
            $this->cache->invalidate($this->getCacheKey($id));
        }
        
        return $result;
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey($id),
            fn() => $this->repository->find($id)
        );
    }

    public function publish(int $id): bool
    {
        $operation = new PublishContentOperation(
            $id,
            $this->repository,
            $this->validator,
            $this->events
        );
        
        $result = $this->security->executeCriticalOperation($operation);
        
        if ($result) {
            $this->cache->invalidate($this->getCacheKey($id));
            $this->events->dispatch(new ContentPublished($id));
        }
        
        return $result;
    }

    public function version(int $id): ContentVersion
    {
        return $this->security->executeCriticalOperation(new CreateVersionOperation(
            $id,
            $this->repository,
            $this->events
        ));
    }

    public function restore(int $id, int $versionId): bool
    {
        $operation = new RestoreVersionOperation(
            $id,
            $versionId,
            $this->repository,
            $this->validator,
            $this->events
        );
        
        $result = $this->security->executeCriticalOperation($operation);
        
        if ($result) {
            $this->cache->invalidate($this->getCacheKey($id));
            $this->events->dispatch(new ContentRestored($id, $versionId));
        }
        
        return $result;
    }

    public function list(array $criteria): Collection
    {
        $cacheKey = $this->getListCacheKey($criteria);
        
        return $this->cache->remember(
            $cacheKey,
            fn() => $this->repository->findByCriteria($criteria)
        );
    }

    private function getCacheKey(int $id): string
    {
        return "content.{$id}";
    }

    private function getListCacheKey(array $criteria): string
    {
        return 'content.list.' . md5(serialize($criteria));
    }
}

final class CreateContentOperation implements CriticalOperation
{
    private array $data;
    private Repository $repository;
    private ValidationService $validator;
    private EventDispatcher $events;

    public function __construct(
        array $data,
        Repository $repository,
        ValidationService $validator,
        EventDispatcher $events
    ) {
        $this->data = $data;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function execute(): Content
    {
        $validatedData = $this->validator->validate($this->data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ]);

        $content = $this->repository->create($validatedData);
        $this->events->dispatch(new ContentCreated($content));
        
        return $content;
    }

    public function getRequiredPermission(): string
    {
        return 'content.create';
    }

    public function getRateLimitKey(): string
    {
        return 'content.create';
    }

    public function requiresRecovery(): bool
    {
        return true;
    }
}

final class UpdateContentOperation implements CriticalOperation
{
    private int $id;
    private array $data;
    private Repository $repository;
    private ValidationService $validator;
    private EventDispatcher $events;

    public function __construct(
        int $id,
        array $data,
        Repository $repository,
        ValidationService $validator,
        EventDispatcher $events
    ) {
        $this->id = $id;
        $this->data = $data;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function execute(): Content
    {
        $validatedData = $this->validator->validate($this->data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published'
        ]);

        $content = $this->repository->update($this->id, $validatedData);
        $this->events->dispatch(new ContentUpdated($content));
        
        return $content;
    }

    public function getRequiredPermission(): string
    {
        return 'content.update';
    }

    public function getRateLimitKey(): string
    {
        return "content.update.{$this->id}";
    }

    public function requiresRecovery(): bool
    {
        return true;
    }
}

interface ContentManagementInterface
{
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
    public function find(int $id): ?Content;
    public function publish(int $id): bool;
    public function version(int $id): ContentVersion;
    public function restore(int $id, int $versionId): bool;
    public function list(array $criteria): Collection;
}

interface CriticalOperation
{
    public function execute(): mixed;
    public function getRequiredPermission(): string;
    public function getRateLimitKey(): string;
    public function requiresRecovery(): bool;
}
