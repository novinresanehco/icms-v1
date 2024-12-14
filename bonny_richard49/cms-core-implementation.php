<?php
namespace App\Core\CMS;

/**
 * CRITICAL CMS CORE
 * Security Integration Required
 */
class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function createContent(array $data, SecurityContext $context): Content 
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->repository),
            $context
        );
    }

    public function updateContent(int $id, array $data, SecurityContext $context): Content 
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->repository),
            $context
        );
    }

    public function getContent(int $id, SecurityContext $context): ?Content 
    {
        return $this->cache->remember(
            "content.{$id}",
            function() use ($id, $context) {
                return $this->security->executeCriticalOperation(
                    new GetContentOperation($id, $this->repository),
                    $context
                );
            },
            3600
        );
    }

    protected function validateContentData(array $data): array 
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ];

        return $this->validator->validate($data, $rules);
    }

    protected function logContentOperation(
        string $operation, 
        $contentId, 
        SecurityContext $context
    ): void {
        $this->auditLogger->log(
            'content_operation',
            [
                'operation' => $operation,
                'content_id' => $contentId,
                'user_id' => $context->getUserId(),
                'timestamp' => time(),
                'ip_address' => $context->getIpAddress()
            ]
        );
    }
}

class CreateContentOperation implements CriticalOperation 
{
    private array $data;
    private ContentRepository $repository;

    public function execute(): Content 
    {
        return $this->repository->create($this->data);
    }

    public function getValidationRules(): array 
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ];
    }

    public function getRequiredPermissions(): array 
    {
        return ['content.create'];
    }

    public function getRateLimitKey(): string 
    {
        return 'content.create';
    }
}