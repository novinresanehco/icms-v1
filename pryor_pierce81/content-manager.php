<?php

namespace App\Core\Content;

class ContentManagementService implements ContentManagerInterface
{
    private ContentRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function __construct(
        ContentRepository $repository,
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

    public function create(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation(
                $this->repository,
                $this->validator,
                $this->cache,
                $data
            ),
            $context
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation(
                $this->repository,
                $this->validator,
                $this->cache,
                $id,
                $data
            ),
            $context
        );
    }

    public function publish(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation(
                $this->repository,
                $this->validator,
                $this->cache,
                $id
            ),
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation(
                $this->repository,
                $this->validator,
                $this->cache,
                $id
            ),
            $context
        );
    }

    public function get(int $id, SecurityContext $context): ?Content
    {
        $cacheKey = "content.{$id}";

        return $this->cache->remember($cacheKey, 3600, function() use ($id, $context) {
            return $this->security->executeCriticalOperation(
                new RetrieveContentOperation(
                    $this->repository,
                    $this->validator,
                    $id
                ),
                $context
            );
        });
    }

    public function version(int $id, SecurityContext $context): ContentVersion
    {
        return $this->security->executeCriticalOperation(
            new VersionContentOperation(
                $this->repository,
                $this->validator,
                $this->cache,
                $id
            ),
            $context
        );
    }

    public function restore(int $id, int $versionId, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new RestoreContentOperation(
                $this->repository,
                $this->validator,
                $this->cache,
                $id,
                $versionId
            ),
            $context
        );
    }

    public function search(array $criteria, SecurityContext $context): Collection
    {
        $cacheKey = 'content.search.' . md5(serialize($criteria));

        return $this->cache->remember($cacheKey, 1800, function() use ($criteria, $context) {
            return $this->security->executeCriticalOperation(
                new SearchContentOperation(
                    $this->repository,
                    $this->validator,
                    $criteria
                ),
                $context
            );
        });
    }

    public function validateContent(array $data): ValidationResult
    {
        return $this->validator->validateInput($data, [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['required', 'in:draft,published'],
            'meta' => ['array'],
            'author_id' => ['required', 'integer', 'exists:users,id']
        ]);
    }

    private function clearContentCache(int $id): void
    {
        $this->cache->forget("content.{$id}");
        $this->cache->tags(['content'])->flush();
    }
}
