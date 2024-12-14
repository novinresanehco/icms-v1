<?php

namespace App\Core\CMS\Security;

class ContentSecurityManager implements SecurityManagerInterface 
{
    private SecurityManager $security;
    private PermissionRegistry $permissions;
    private AuditLogger $logger;

    public function __construct(
        SecurityManager $security,
        PermissionRegistry $permissions,
        AuditLogger $logger
    ) {
        $this->security = $security;
        $this->permissions = $permissions;
        $this->logger = $logger;
    }

    public function validateAccess(Content $content, SecurityContext $context): bool
    {
        $operation = new ContentAccessOperation($content, $this->permissions);
        return $this->security->validateOperation($operation, $context);
    }
}

class ContentAccessOperation implements SecurityOperation
{
    private Content $content;
    private PermissionRegistry $permissions;

    public function __construct(Content $content, PermissionRegistry $permissions)
    {
        $this->content = $content;
        $this->permissions = $permissions;
    }

    public function getRequiredPermissions(): array
    {
        $permissions = ['content.read'];

        if ($this->content->status === 'draft') {
            $permissions[] = 'content.read.draft';
        }

        return $permissions;
    }

    public function getContext(): array
    {
        return [
            'content_id' => $this->content->id,
            'content_type' => $this->content->type,
            'content_status' => $this->content->status
        ];
    }
}

class ContentValidationService implements ValidationInterface
{
    private ValidationService $validator;
    private SecurityConfig $config;

    public function __construct(ValidationService $validator, SecurityConfig $config)
    {
        $this->validator = $validator;
        $this->config = $config;
    }

    public function validateContent(ContentData $data): ValidationResult
    {
        $rules = $this->config->getContentValidationRules();
        return $this->validator->validate($data->toArray(), $rules);
    }

    public function validatePublishState(Content $content): ValidationResult
    {
        $rules = $this->config->getPublishValidationRules();
        return $this->validator->validate($content->toArray(), $rules);
    }
}

class ContentAuditLogger implements AuditInterface
{
    private AuditLogger $logger;

    public function logAccess(Content $content, SecurityContext $context): void
    {
        $this->logger->log('content_access', [
            'content_id' => $content->id,
            'user_id' => $context->getUser()->id,
            'action' => 'access',
            'timestamp' => now()
        ]);
    }

    public function logModification(Content $content, string $action, SecurityContext $context): void
    {
        $this->logger->log('content_modification', [
            'content_id' => $content->id,
            'user_id' => $context->getUser()->id,
            'action' => $action,
            'changes' => $content->getChanges(),
            'timestamp' => now()
        ]);
    }
}