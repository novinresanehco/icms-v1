<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Data\{Repository, CacheManager};
use App\Core\Validation\ValidationService;
use App\Core\Exceptions\{ContentException, SecurityException};

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private MetricsCollector $metrics;

    public function create(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->repository, $this->validator),
            $context
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->repository, $this->validator),
            $context
        );
    }

    public function publish(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, $this->repository, $this->validator),
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $this->repository),
            $context
        );
    }

    public function retrieve(int $id, SecurityContext $context): Content
    {
        return $this->cache->remember("content.$id", function() use ($id, $context) {
            return $this->security->executeCriticalOperation(
                new RetrieveContentOperation($id, $this->repository),
                $context
            );
        });
    }

    public function version(int $id, SecurityContext $context): ContentVersion
    {
        return $this->security->executeCriticalOperation(
            new VersionContentOperation($id, $this->repository),
            $context
        );
    }

    public function attachMedia(int $contentId, array $mediaIds, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new AttachMediaOperation($contentId, $mediaIds, $this->repository),
            $context
        );
    }

    public function setPermissions(int $contentId, array $permissions, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new SetPermissionsOperation($contentId, $permissions, $this->repository),
            $context
        );
    }

    public function render(int $contentId, SecurityContext $context): string
    {
        return $this->cache->remember("rendered.$contentId", function() use ($contentId, $context) {
            return $this->security->executeCriticalOperation(
                new RenderContentOperation($contentId, $this->repository),
                $context
            );
        });
    }

    public function search(array $criteria, SecurityContext $context): Collection
    {
        return $this->security->executeCriticalOperation(
            new SearchContentOperation($criteria, $this->repository),
            $context
        );
    }

    private function validateContent(array $data): array
    {
        $rules = [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'array',
            'meta' => 'array'
        ];

        return $this->validator->validate($data, $rules);
    }

    private function cacheContent(Content $content): void
    {
        $this->cache->tags(['content'])
            ->put("content.{$content->id}", $content, 3600);
            
        $this->cache->tags(['rendered'])
            ->put("rendered.{$content->id}", $content->rendered, 3600);
    }

    private function invalidateCache(Content $content): void
    {
        $this->cache->tags(['content', 'rendered'])
            ->forget("content.{$content->id}");
    }

    private function trackMetrics(string $operation, Content $content): void
    {
        $this->metrics->increment("content.$operation");
        $this->metrics->gauge("content.size", strlen($content->content));
        $this->metrics->timing("content.render", $content->renderTime);
    }
}
