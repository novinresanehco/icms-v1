<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Security\Services\ValidationService;
use App\Core\CMS\Services\{
    ContentService,
    VersioningService,
    MediaService
};
use App\Core\Interfaces\CMSManagerInterface;
use Illuminate\Support\Facades\{DB, Cache};

class CMSManager implements CMSManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ContentService $content;
    private VersioningService $versioning;
    private MediaService $media;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        ContentService $content,
        VersioningService $versioning,
        MediaService $media,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->content = $content;
        $this->versioning = $versioning;
        $this->media = $media;
        $this->auditLogger = $auditLogger;
    }

    public function createContent(array $data): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, [
                'validator' => $this->validator,
                'content' => $this->content,
                'versioning' => $this->versioning,
                'logger' => $this->auditLogger
            ])
        );
    }

    public function updateContent(int $id, array $data): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, [
                'validator' => $this->validator,
                'content' => $this->content,
                'versioning' => $this->versioning,
                'logger' => $this->auditLogger
            ])
        );
    }

    public function publishContent(int $id): PublishResult
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, [
                'validator' => $this->validator,
                'content' => $this->content,
                'versioning' => $this->versioning,
                'logger' => $this->auditLogger
            ])
        );
    }

    public function attachMedia(int $contentId, array $mediaData): MediaResult
    {
        return $this->security->executeCriticalOperation(
            new AttachMediaOperation($contentId, $mediaData, [
                'validator' => $this->validator,
                'content' => $this->content,
                'media' => $this->media,
                'logger' => $this->auditLogger
            ])
        );
    }

    protected function validateContentOperation(string $operation, array $data): void
    {
        $validationContext = new ValidationContext($operation, $data);
        
        if (!$this->validator->validateOperation($validationContext)) {
            throw new ValidationException("Content operation validation failed: {$operation}");
        }
    }

    protected function ensureContentSecurity(int $contentId, string $operation): void
    {
        $securityContext = new SecurityContext($contentId, $operation);
        
        if (!$this->security->validateAccess($securityContext)) {
            throw new SecurityException("Security validation failed for content: {$contentId}");
        }
    }

    protected function handleContentCache(int $contentId): void
    {
        Cache::tags(['content'])->forget($contentId);
    }
}

class CreateContentOperation implements CriticalOperation
{
    private array $data;
    private array $services;

    public function __construct(array $data, array $services)
    {
        $this->data = $data;
        $this->services = $services;
    }

    public function execute(): ContentResult
    {
        // Validate content data
        $this->services['validator']->validateInput($this->data);

        // Create content version
        $version = $this->services['versioning']->createVersion($this->data);

        // Store content
        $content = $this->services['content']->store([
            'data' => $this->data,
            'version_id' => $version->id
        ]);

        // Log operation
        $this->services['logger']->logContentCreation($content);

        return new ContentResult($content, $version);
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
}

class UpdateContentOperation implements CriticalOperation
{
    private int $id;
    private array $data;
    private array $services;

    public function execute(): ContentResult
    {
        // Verify current version
        $currentVersion = $this->services['versioning']->getCurrentVersion($this->id);
        
        // Create new version
        $newVersion = $this->services['versioning']->createVersion($this->data);

        // Update content
        $content = $this->services['content']->update($this->id, [
            'data' => $this->data,
            'version_id' => $newVersion->id,
            'previous_version_id' => $currentVersion->id
        ]);

        // Log operation
        $this->services['logger']->logContentUpdate($content, $currentVersion, $newVersion);

        return new ContentResult($content, $newVersion);
    }
}

class PublishContentOperation implements CriticalOperation
{
    private int $id;
    private array $services;

    public function execute(): PublishResult
    {
        // Verify content is ready for publication
        $this->verifyPublicationRequirements();

        // Create publication version
        $publishVersion = $this->services['versioning']->createPublicationVersion($this->id);

        // Publish content
        $publishedContent = $this->services['content']->publish([
            'id' => $this->id,
            'version_id' => $publishVersion->id,
            'published_at' => now()
        ]);

        // Log publication
        $this->services['logger']->logContentPublication($publishedContent, $publishVersion);

        return new PublishResult($publishedContent, $publishVersion);
    }

    private function verifyPublicationRequirements(): void
    {
        $requirements = new PublicationRequirements($this->id);
        
        if (!$requirements->verify()) {
            throw new PublicationException('Content does not meet publication requirements');
        }
    }
}
