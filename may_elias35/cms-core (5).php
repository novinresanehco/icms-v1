<?php

namespace App\Core\CMS;

use App\Core\Security\CoreSecurityManager;
use App\Core\Events\ContentEvent;
use App\Core\Exceptions\CMSException;

class ContentManager implements ContentManagerInterface
{
    private CoreSecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        CoreSecurityManager $security,
        ContentRepository $repository,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function createContent(array $data, SecurityContext $context): Content
    {
        $operation = new CreateContentOperation($data);
        
        return $this->security->executeCriticalOperation($operation, $context)
            ->getResult();
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content
    {
        $operation = new UpdateContentOperation($id, $data);
        
        return $this->security->executeCriticalOperation($operation, $context)
            ->getResult();
    }

    public function deleteContent(int $id, SecurityContext $context): bool
    {
        $operation = new DeleteContentOperation($id);
        
        return $this->security->executeCriticalOperation($operation, $context)
            ->getResult();
    }

    public function getContent(int $id, SecurityContext $context): ?Content
    {
        return $this->cache->remember("content.$id", function() use ($id, $context) {
            $operation = new RetrieveContentOperation($id);
            
            return $this->security->executeCriticalOperation($operation, $context)
                ->getResult();
        });
    }

    public function publishContent(int $id, SecurityContext $context): bool
    {
        $operation = new PublishContentOperation($id);
        
        return $this->security->executeCriticalOperation($operation, $context)
            ->getResult();
    }

    public function versionContent(int $id, SecurityContext $context): ContentVersion
    {
        $operation = new VersionContentOperation($id);
        
        return $this->security->executeCriticalOperation($operation, $context)
            ->getResult();
    }

    protected function validateContent(array $data): array
    {
        return $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id'
        ]);
    }
}

abstract class ContentOperation implements CriticalOperation
{
    protected array $data;
    protected ContentRepository $repository;
    protected ValidationService $validator;
    protected EventDispatcher $events;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->repository = app(ContentRepository::class);
        $this->validator = app(ValidationService::class);
        $this->events = app(EventDispatcher::class);
    }

    abstract public function execute(): OperationResult;
    
    public function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived'
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['content.manage'];
    }

    public function getRateLimitKey(): string
    {
        return 'content.operations';
    }
}

class CreateContentOperation extends ContentOperation
{
    public function execute(): OperationResult
    {
        $validated = $this->validator->validate($this->data);
        
        $content = $this->repository->create($validated);
        
        $this->events->dispatch(new ContentCreated($content));
        
        return new OperationResult($content);
    }
}
