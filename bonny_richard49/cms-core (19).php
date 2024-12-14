<?php

namespace App\Core\Content;

use App\Core\Security\CoreSecurityManager;
use App\Core\Security\SecurityContext;
use App\Core\Security\CriticalOperation;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Core\Database\Repository;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagementInterface 
{
    private CoreSecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private MediaManager $mediaManager;
    private VersionManager $versionManager;

    public function __construct(
        CoreSecurityManager $security,
        Repository $repository,
        CacheManager $cache,
        ValidationService $validator,
        MediaManager $mediaManager,
        VersionManager $versionManager
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->mediaManager = $mediaManager;
        $this->versionManager = $versionManager;
    }

    public function create(array $data): Content 
    {
        $operation = new ContentOperation(
            'create',
            $data,
            $this->getCreateValidationRules()
        );

        return $this->security->executeCriticalOperation(
            $operation,
            $this->getSecurityContext()
        );
    }

    public function update(int $id, array $data): Content 
    {
        $operation = new ContentOperation(
            'update',
            ['id' => $id, 'data' => $data],
            $this->getUpdateValidationRules()
        );

        return $this->security->executeCriticalOperation(
            $operation,
            $this->getSecurityContext()
        );
    }

    public function delete(int $id): bool 
    {
        $operation = new ContentOperation(
            'delete',
            ['id' => $id],
            $this->getDeleteValidationRules()
        );

        return $this->security->executeCriticalOperation(
            $operation,
            $this->getSecurityContext()
        );
    }

    public function publishContent(int $id): bool 
    {
        $operation = new ContentOperation(
            'publish',
            ['id' => $id],
            $this->getPublishValidationRules()
        );

        return $this->security->executeCriticalOperation(
            $operation,
            $this->getSecurityContext()
        );
    }

    public function versionContent(int $id): ContentVersion 
    {
        $operation = new ContentOperation(
            'version',
            ['id' => $id],
            $this->getVersionValidationRules()
        );

        return $this->security->executeCriticalOperation(
            $operation,
            $this->getSecurityContext()
        );
    }

    public function attachMedia(int $contentId, array $mediaIds): void 
    {
        $operation = new ContentOperation(
            'attachMedia',
            [
                'content_id' => $contentId,
                'media_ids' => $mediaIds
            ],
            $this->getMediaAttachmentRules()
        );

        $this->security->executeCriticalOperation(
            $operation,
            $this->getSecurityContext()
        );
    }

    protected function executeContentOperation(string $type, array $data): mixed 
    {
        return DB::transaction(function() use ($type, $data) {
            switch ($type) {
                case 'create':
                    $content = $this->repository->create($data);
                    $this->versionManager->createInitialVersion($content);
                    $this->cache->invalidateContentCache($content->id);
                    return $content;

                case 'update':
                    $content = $this->repository->update($data['id'], $data['data']);
                    $this->versionManager->createVersion($content);
                    $this->cache->invalidateContentCache($content->id);
                    return $content;

                case 'delete':
                    $this->versionManager->archiveVersions($data['id']);
                    $this->cache->invalidateContentCache($data['id']);
                    return $this->repository->delete($data['id']);

                case 'publish':
                    $content = $this->repository->find($data['id']);
                    $content->publish();
                    $this->cache->invalidateContentCache($content->id);
                    return true;

                case 'version':
                    $content = $this->repository->find($data['id']);
                    return $this->versionManager->createVersion($content);

                case 'attachMedia':
                    $content = $this->repository->find($data['content_id']);
                    $this->mediaManager->validateMediaIds($data['media_ids']);
                    $content->attachMedia($data['media_ids']);
                    $this->cache->invalidateContentCache($content->id);
                    return true;

                default:
                    throw new \InvalidArgumentException("Invalid operation type: {$type}");
            }
        });
    }

    protected function getCreateValidationRules(): array 
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id',
            'meta' => 'array'
        ];
    }

    protected function getUpdateValidationRules(): array 
    {
        return [
            'id' => 'required|exists:contents,id',
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published',
            'category_id' => 'exists:categories,id',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id',
            'meta' => 'array'
        ];
    }

    protected function getSecurityContext(): SecurityContext 
    {
        return new SecurityContext(
            auth()->user(),
            request()->ip(),
            request()->userAgent()
        );
    }

    protected function cacheContent(Content $content): void 
    {
        $this->cache->remember(
            $this->getCacheKey($content->id),
            $content,
            config('cache.content.ttl')
        );
    }

    protected function getCacheKey(int $contentId): string 
    {
        return "content.{$contentId}";
    }
}

class ContentOperation extends CriticalOperation 
{
    private string $type;
    private array $data;
    private array $rules;

    public function __construct(string $type, array $data, array $rules) 
    {
        $this->type = $type;
        $this->data = $data;
        $this->rules = $rules;
    }

    public function execute(): mixed 
    {
        return $this->executeContentOperation($this->type, $this->data);
    }

    public function getValidationRules(): array 
    {
        return $this->rules;
    }

    public function getData(): array 
    {
        return $this->data;
    }

    public function getType(): string 
    {
        return "content.{$this->type}";
    }

    public function getRequiredPermissions(): array 
    {
        return ["content.{$this->type}"];
    }

    public function getRateLimitKey(): string 
    {
        return "content.{$this->type}";
    }
}
