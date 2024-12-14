namespace App\Core\Content;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

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

    public function deleteContent(int $id, SecurityContext $context): bool 
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $this->repository),
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

    protected function logContentOperation(string $operation, $contentId, SecurityContext $context): void 
    {
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

    public function __construct(array $data, ContentRepository $repository) 
    {
        $this->data = $data;
        $this->repository = $repository;
    }

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

class ContentRepository 
{
    private DB $db;
    private ValidationService $validator;

    public function create(array $data): Content 
    {
        $validated = $this->validator->validate($data);
        
        return DB::transaction(function() use ($validated) {
            $content = Content::create($validated);
            $this->createContentVersion($content);
            return $content;
        });
    }

    public function update(int $id, array $data): Content 
    {
        $validated = $this->validator->validate($data);
        
        return DB::transaction(function() use ($id, $validated) {
            $content = Content::findOrFail($id);
            $content->update($validated);
            $this->createContentVersion($content);
            return $content;
        });
    }

    private function createContentVersion(Content $content): void 
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_at' => now()
        ]);
    }
}