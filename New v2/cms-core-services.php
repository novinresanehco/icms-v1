<?php
namespace App\Core\CMS;

/**
 * Core CMS service handling all critical content operations
 * with comprehensive security and audit logging.
 */
class ContentService implements ContentServiceInterface
{
    private SecurityManager $security;
    private ContentRepository $content;
    private CacheManager $cache;
    private AuditLogger $audit;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        ContentRepository $content,
        CacheManager $cache,
        AuditLogger $audit,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    /**
     * Creates new content with full security validation and audit logging
     */
    public function create(array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->content, $this->cache, $this->audit),
            $context
        );
    }

    /**
     * Updates existing content with version control and security checks
     */
    public function update(int $id, array $data, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->content, $this->cache, $this->audit),
            $context
        );
    }

    /**
     * Retrieves content with caching and security verification
     */
    public function retrieve(int $id, SecurityContext $context): Content
    {
        return $this->security->executeCriticalOperation(
            new RetrieveContentOperation($id, $this->content, $this->cache, $this->audit),
            $context
        );
    }

    /**
     * Deletes content with security validation and audit logging
     */
    public function delete(int $id, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $this->content, $this->cache, $this->audit),
            $context
        );
    }
}

/**
 * Operation for creating new content with security controls
 */
class CreateContentOperation extends CriticalOperation
{
    private array $data;
    private ContentRepository $content;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function execute(): Content
    {
        // Pre-execution validation
        $this->validateData();
        
        // Create content with versioning
        $content = $this->content->create([
            'data' => $this->secureData($this->data),
            'version' => 1,
            'status' => ContentStatus::DRAFT
        ]);
        
        // Clear relevant caches
        $this->cache->invalidatePattern("content.*");
        
        // Log operation
        $this->audit->logContentCreate($content);
        
        return $content;
    }

    public function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'type' => 'required|string|in:page,post,article'
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['content.create'];
    }

    private function validateData(): void
    {
        if (!$this->validator->validate($this->data, $this->getValidationRules())) {
            throw new ValidationException('Invalid content data');
        }
    }

    private function secureData(array $data): string
    {
        return $this->encryption->encrypt(json_encode($data));
    }
}

/**
 * Operation for updating content with version control
 */
class UpdateContentOperation extends CriticalOperation
{
    private int $id;
    private array $data;
    private ContentRepository $content;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function execute(): Content
    {
        // Load existing content
        $content = $this->content->find($this->id);
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$this->id}");
        }

        // Create new version
        $newVersion = $content->version + 1;
        
        // Update content with versioning
        $updated = $this->content->update($this->id, [
            'data' => $this->secureData($this->data),
            'version' => $newVersion,
            'previous_version' => $content->version
        ]);

        // Clear caches
        $this->cache->invalidatePattern("content.{$this->id}.*");
        
        // Log operation
        $this->audit->logContentUpdate($updated, $content);
        
        return $updated;
    }

    public function getRequiredPermissions(): array
    {
        return ['content.update'];
    }
}

/**
 * Operation for retrieving content with security checks
 */
class RetrieveContentOperation extends CriticalOperation 
{
    private int $id;
    private ContentRepository $content;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function execute(): Content
    {
        return $this->cache->remember(
            "content.{$this->id}", 
            function() {
                $content = $this->content->find($this->id);
                if (!$content) {
                    throw new ContentNotFoundException("Content not found: {$this->id}");
                }
                
                // Log access
                $this->audit->logContentAccess($content);
                
                return $content;
            }
        );
    }

    public function getRequiredPermissions(): array
    {
        return ['content.read'];
    }
}

/**
 * Operation for deleting content with security validation
 */
class DeleteContentOperation extends CriticalOperation
{
    private int $id;
    private ContentRepository $content;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Load content
        $content = $this->content->find($this->id);
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$this->id}");
        }

        // Archive content
        $this->content->archive($content);
        
        // Perform delete
        $this->content->delete($this->id);
        
        // Clear caches
        $this->cache->invalidatePattern("content.{$this->id}.*");
        
        // Log operation
        $this->audit->logContentDelete($content);
    }

    public function getRequiredPermissions(): array
    {
        return ['content.delete'];
    }
}

/**
 * Content repository implementation
 */
class ContentRepository extends BaseRepository
{
    protected function model(): string
    {
        return Content::class;
    }

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = $this->model->create($data);
            $this->createVersion($content, $data);
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->find($id);
            $content->update($data);
            $this->createVersion($content, $data);
            return $content;
        });
    }

    private function createVersion(Content $content, array $data): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'version' => $content->version,
            'data' => $data
        ]);
    }
}
