namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        Repository $repository,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(new class($data, $this->repository, $this->validator) implements CriticalOperation {
            private array $data;
            private Repository $repository;
            private ValidationService $validator;

            public function __construct(array $data, Repository $repository, ValidationService $validator) 
            {
                $this->data = $data;
                $this->repository = $repository;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $content = $this->repository->create($this->data);
                return new OperationResult($content, ['id' => $content->id]);
            }

            public function getValidationRules(): array
            {
                return [
                    'title' => 'required|max:255',
                    'content' => 'required',
                    'status' => 'required|in:draft,published',
                    'author_id' => 'required|exists:users,id'
                ];
            }

            public function getData(): array 
            {
                return $this->data;
            }

            public function getRequiredPermissions(): array
            {
                return ['content.create'];
            }

            public function getRateLimitKey(): string
            {
                return 'content:create';
            }
        });
    }

    public function update(int $id, array $data): Content
    {
        $operation = new class($id, $data, $this->repository, $this->cache) implements CriticalOperation {
            private int $id;
            private array $data;
            private Repository $repository;
            private CacheManager $cache;

            public function __construct(int $id, array $data, Repository $repository, CacheManager $cache)
            {
                $this->id = $id;
                $this->data = $data;
                $this->repository = $repository;
                $this->cache = $cache;
            }

            public function execute(): OperationResult
            {
                $content = $this->repository->update($this->id, $this->data);
                $this->cache->forget("content:{$this->id}");
                return new OperationResult($content);
            }

            public function getValidationRules(): array
            {
                return [
                    'title' => 'sometimes|required|max:255',
                    'content' => 'sometimes|required',
                    'status' => 'sometimes|required|in:draft,published'
                ];
            }

            public function getData(): array
            {
                return array_merge(['id' => $this->id], $this->data);
            }

            public function getRequiredPermissions(): array
            {
                return ['content.update'];
            }

            public function getRateLimitKey(): string
            {
                return "content:update:{$this->id}";
            }
        };

        return $this->security->executeCriticalOperation($operation);
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(new class($id, $this->repository, $this->cache) implements CriticalOperation {
            private int $id;
            private Repository $repository;
            private CacheManager $cache;

            public function __construct(int $id, Repository $repository, CacheManager $cache)
            {
                $this->id = $id;
                $this->repository = $repository;
                $this->cache = $cache;
            }

            public function execute(): OperationResult
            {
                $result = $this->repository->delete($this->id);
                $this->cache->forget("content:{$this->id}");
                return new OperationResult($result);
            }

            public function getValidationRules(): array
            {
                return ['id' => 'required|exists:content'];
            }

            public function getData(): array
            {
                return ['id' => $this->id];
            }

            public function getRequiredPermissions(): array
            {
                return ['content.delete'];
            }

            public function getRateLimitKey(): string
            {
                return "content:delete:{$this->id}";
            }
        });
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(new class($id, $this->repository, $this->cache, $this->validator) implements CriticalOperation {
            private int $id;
            private Repository $repository;
            private CacheManager $cache;
            private ValidationService $validator;

            public function __construct(int $id, Repository $repository, CacheManager $cache, ValidationService $validator)
            {
                $this->id = $id;
                $this->repository = $repository;
                $this->cache = $cache;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $content = $this->repository->find($this->id);
                
                if (!$this->validator->validatePublishState($content)) {
                    throw new ValidationException('Content not ready for publishing');
                }

                $result = $this->repository->publish($this->id);
                $this->cache->forget("content:{$this->id}");
                
                return new OperationResult($result);
            }

            public function getValidationRules(): array
            {
                return ['id' => 'required|exists:content'];
            }

            public function getData(): array
            {
                return ['id' => $this->id];
            }

            public function getRequiredPermissions(): array
            {
                return ['content.publish'];
            }

            public function getRateLimitKey(): string
            {
                return "content:publish:{$this->id}";
            }
        });
    }

    public function versionContent(int $id): ContentVersion
    {
        return $this->security->executeCriticalOperation(new class($id, $this->repository) implements CriticalOperation {
            private int $id;
            private Repository $repository;

            public function __construct(int $id, Repository $repository)
            {
                $this->id = $id;
                $this->repository = $repository;
            }

            public function execute(): OperationResult
            {
                $version = $this->repository->createVersion($this->id);
                return new OperationResult($version);
            }

            public function getValidationRules(): array
            {
                return ['id' => 'required|exists:content'];
            }

            public function getData(): array
            {
                return ['id' => $this->id];
            }

            public function getRequiredPermissions(): array
            {
                return ['content.version'];
            }

            public function getRateLimitKey(): string
            {
                return "content:version:{$this->id}";
            }
        });
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember("content:{$id}", function() use ($id) {
            return $this->repository->find($id);
        });
    }

    public function validateContent(Content $content): bool
    {
        return $this->validator->validate($content->toArray(), [
            'title' => 'required|max:255',
            'content' => 'required',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'published_at' => 'nullable|date'
        ]);
    }
}
