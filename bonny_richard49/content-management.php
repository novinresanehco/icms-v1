namespace App\Core\Content;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private MediaManager $media;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        CacheManager $cache,
        ValidationService $validator,
        MediaManager $media
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->media = $media;
    }

    public function create(array $data, SecurityContext $context): Content 
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->repository, $this->validator),
            $context
        );
    }

    public function update(int $id, array $data, SecurityContext $context): Content 
    {
        $operation = new UpdateContentOperation(
            $id, 
            $data, 
            $this->repository,
            $this->validator,
            $this->cache
        );

        return $this->security->executeCriticalOperation($operation, $context);
    }

    public function delete(int $id, SecurityContext $context): bool 
    {
        $operation = new DeleteContentOperation(
            $id,
            $this->repository,
            $this->cache,
            $this->media
        );

        return $this->security->executeCriticalOperation($operation, $context);
    }

    public function publish(int $id, SecurityContext $context): bool 
    {
        $operation = new PublishContentOperation(
            $id,
            $this->repository,
            $this->cache,
            $this->validator
        );

        return $this->security->executeCriticalOperation($operation, $context);
    }

    public function version(int $id, SecurityContext $context): ContentVersion 
    {
        $operation = new VersionContentOperation(
            $id,
            $this->repository,
            $this->cache
        );

        return $this->security->executeCriticalOperation($operation, $context);
    }

    public function find(int $id, SecurityContext $context): ?Content 
    {
        return $this->cache->remember("content.{$id}", function() use ($id, $context) {
            $operation = new FindContentOperation(
                $id,
                $this->repository
            );

            return $this->security->executeCriticalOperation($operation, $context);
        });
    }

    public function list(array $criteria, SecurityContext $context): ContentCollection 
    {
        $cacheKey = $this->buildCacheKey('list', $criteria);

        return $this->cache->remember($cacheKey, function() use ($criteria, $context) {
            $operation = new ListContentOperation(
                $criteria,
                $this->repository
            );

            return $this->security->executeCriticalOperation($operation, $context);
        });
    }

    private function buildCacheKey(string $operation, array $params): string 
    {
        return 'content.' . $operation . '.' . md5(serialize($params));
    }
}

class CreateContentOperation implements CriticalOperation 
{
    private array $data;
    private ContentRepository $repository;
    private ValidationService $validator;

    public function __construct(
        array $data, 
        ContentRepository $repository,
        ValidationService $validator
    ) {
        $this->data = $data;
        $this->repository = $repository;
        $this->validator = $validator;
    }

    public function execute(): Content 
    {
        $validated = $this->validator->validate($this->data, [
            'title' => 'required|max:200',
            'body' => 'required',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ]);

        return $this->repository->create($validated);
    }

    public function getValidationRules(): array 
    {
        return [
            'permission' => 'create_content',
            'rate_limit' => 'content_creation'
        ];
    }

    public function getSecurityRequirements(): array 
    {
        return [
            'content_validation',
            'author_verification'
        ];
    }
}

class UpdateContentOperation implements CriticalOperation 
{
    private int $id;
    private array $data;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;

    public function execute(): Content 
    {
        $validated = $this->validator->validate($this->data, [
            'title' => 'sometimes|required|max:200',
            'body' => 'sometimes|required',
            'status' => 'sometimes|required|in:draft,published',
        ]);

        $content = $this->repository->update($this->id, $validated);
        $this->cache->forget("content.{$this->id}");

        return $content;
    }

    public function getValidationRules(): array 
    {
        return [
            'permission' => 'edit_content',
            'rate_limit' => 'content_update'
        ];
    }
}

class PublishContentOperation implements CriticalOperation 
{
    private int $id;
    private ContentRepository $repository;
    private CacheManager $cache;
    private ValidationService $validator;

    public function execute(): bool 
    {
        $content = $this->repository->find($this->id);
        
        if (!$content) {
            throw new ContentNotFoundException();
        }

        if (!$this->validator->validateForPublishing($content)) {
            throw new ValidationException('Content not ready for publishing');
        }

        $published = $this->repository->publish($this->id);
        $this->cache->forget("content.{$this->id}");

        return $published;
    }

    public function getValidationRules(): array 
    {
        return [
            'permission' => 'publish_content',
            'rate_limit' => 'content_publishing'
        ];
    }

    public function getSecurityRequirements(): array 
    {
        return [
            'content_validation',
            'publishing_approval'
        ];
    }
}
