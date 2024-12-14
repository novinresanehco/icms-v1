namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
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

    public function createContent(array $data, SecurityContext $context): ContentResult
    {
        return $this->security->executeSecureOperation(
            function() use ($data, $context) {
                // Validate content data
                $validated = $this->validator->validateContent($data);
                
                // Store with transaction protection
                $content = DB::transaction(function() use ($validated, $context) {
                    $content = $this->repository->create($validated);
                    $this->auditLogger->logContentCreation($content, $context);
                    $this->cache->invalidateContentCache();
                    return $content;
                });
                
                return new ContentResult($content);
            },
            $context
        );
    }

    public function updateContent(string $id, array $data, SecurityContext $context): ContentResult
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $data, $context) {
                // Validate update data
                $validated = $this->validator->validateContent($data);
                
                // Update with transaction protection
                $content = DB::transaction(function() use ($id, $validated, $context) {
                    // Load existing content
                    $content = $this->repository->findOrFail($id);
                    
                    // Perform update
                    $updated = $this->repository->update($content, $validated);
                    
                    // Log and cache
                    $this->auditLogger->logContentUpdate($updated, $context);
                    $this->cache->invalidateContent($id);
                    
                    return $updated;
                });
                
                return new ContentResult($content);
            },
            $context
        );
    }

    public function deleteContent(string $id, SecurityContext $context): void
    {
        $this->security->executeSecureOperation(
            function() use ($id, $context) {
                DB::transaction(function() use ($id, $context) {
                    // Load content
                    $content = $this->repository->findOrFail($id);
                    
                    // Perform deletion
                    $this->repository->delete($content);
                    
                    // Log and cache
                    $this->auditLogger->logContentDeletion($content, $context);
                    $this->cache->invalidateContent($id);
                });
            },
            $context
        );
    }

    public function getContent(string $id, SecurityContext $context): ContentResult
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                // Try cache first
                $cacheKey = "content.{$id}";
                
                return $this->cache->remember($cacheKey, function() use ($id, $context) {
                    $content = $this->repository->findOrFail($id);
                    $this->auditLogger->logContentAccess($content, $context);
                    return new ContentResult($content);
                });
            },
            $context
        );
    }

    public function listContents(ContentFilter $filter, SecurityContext $context): ContentCollection
    {
        return $this->security->executeSecureOperation(
            function() use ($filter, $context) {
                $cacheKey = $filter->getCacheKey();
                
                return $this->cache->remember($cacheKey, function() use ($filter, $context) {
                    $contents = $this->repository->list($filter);
                    $this->auditLogger->logContentList($filter, $context);
                    return new ContentCollection($contents);
                });
            },
            $context
        );
    }

    public function publishContent(string $id, SecurityContext $context): ContentResult
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $context) {
                DB::transaction(function() use ($id, $context) {
                    // Load content
                    $content = $this->repository->findOrFail($id);
                    
                    // Update status
                    $content->setStatus(ContentStatus::PUBLISHED);
                    $this->repository->update($content);
                    
                    // Log and cache
                    $this->auditLogger->logContentPublish($content, $context);
                    $this->cache->invalidateContent($id);
                    
                    return new ContentResult($content);
                });
            },
            $context
        );
    }

    public function validateContent(array $data): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
        ];

        return $this->validator->validate($data, $rules);
    }

    private function invalidateRelatedCaches(Content $content): void
    {
        $this->cache->invalidateContent($content->getId());
        $this->cache->invalidateContentLists();
        if ($content->hasCategory()) {
            $this->cache->invalidateCategory($content->getCategoryId());
        }
    }
}
