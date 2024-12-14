<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\ContentException;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        ValidationService $validator,
        CacheManager $cache,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(new CreateContentOperation(
            $data,
            $this->repository,
            $this->validator,
            $this->cache
        ));
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(new UpdateContentOperation(
            $id,
            $data,
            $this->repository,
            $this->validator,
            $this->cache
        ));
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(new DeleteContentOperation(
            $id,
            $this->repository,
            $this->cache
        ));
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(new PublishContentOperation(
            $id,
            $this->repository,
            $this->validator,
            $this->cache
        ));
    }

    public function version(int $id): ContentVersion
    {
        return $this->security->executeCriticalOperation(new VersionContentOperation(
            $id,
            $this->repository,
            $this->cache
        ));
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember("content.$id", 3600, function() use ($id) {
            return $this->repository->find($id);
        });
    }

    public function list(array $criteria): Collection
    {
        $cacheKey = 'content.list.' . md5(serialize($criteria));
        return $this->cache->remember($cacheKey, 3600, function() use ($criteria) {
            return $this->repository->findByCriteria($criteria);
        });
    }

    public function attachMedia(int $contentId, array $mediaIds): void
    {
        $this->security->executeCriticalOperation(new AttachMediaOperation(
            $contentId,
            $mediaIds,
            $this->repository,
            $this->cache
        ));
    }

    public function setPermissions(int $contentId, array $permissions): void
    {
        $this->security->executeCriticalOperation(new SetPermissionsOperation(
            $contentId,
            $permissions,
            $this->repository,
            $this->cache
        ));
    }

    public function validateContent(array $data): array
    {
        return $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'array',
            'meta' => 'array',
            'publish_at' => 'date|nullable',
            'expire_at' => 'date|nullable|after:publish_at'
        ]);
    }

    private function clearRelatedCache(int $contentId): void
    {
        $this->cache->tags(['content'])->forget("content.$contentId");
        $this->cache->tags(['content'])->flush();
    }

    private function recordMetrics(string $operation, int $contentId): void
    {
        $this->metrics->increment("content.$operation", [
            'content_id' => $contentId,
            'timestamp' => time()
        ]);
    }
}

class CreateContentOperation implements CriticalOperation
{
    private array $data;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;

    public function __construct(
        array $data,
        ContentRepository $repository,
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->data = $data;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function execute(): Content
    {
        // Validate input
        $validatedData = $this->validator->validate($this->data);

        // Create content
        $content = $this->repository->create($validatedData);

        // Clear cache
        $this->cache->tags(['content'])->flush();

        return $content;
    }

    public function getRequiredPermissions(): array
    {
        return ['content.create'];
    }

    public function getRateLimitKey(): string
    {
        return 'content.create';
    }

    public function getData(): array
    {
        return $this->data;
    }
}
