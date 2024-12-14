namespace App\Core\CMS;

class ContentManager implements ContentInterface
{
    private Repository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private AuditLogger $logger;

    public function create(array $data): Content 
    {
        return $this->executeOperation(new CreateContentOperation(
            $data,
            $this->repository,
            $this->validator
        ));
    }

    public function update(int $id, array $data): Content 
    {
        return $this->executeOperation(new UpdateContentOperation(
            $id,
            $data,
            $this->repository,
            $this->validator
        ));
    }

    public function delete(int $id): bool 
    {
        return $this->executeOperation(new DeleteContentOperation(
            $id,
            $this->repository
        ));
    }

    private function executeOperation(CriticalOperation $operation): mixed 
    {
        $this->security->validateAccess($operation->getRequiredPermissions());
        
        DB::beginTransaction();
        
        try {
            $result = $operation->execute();
            $this->cache->invalidatePrefix('content');
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

class CreateContentOperation extends CriticalOperation
{
    private array $data;
    private Repository $repository;
    private ValidationService $validator;

    protected function executeInternal(array $data): Content 
    {
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'status' => 'required|in:draft,published'
        ]);

        return $this->repository->create($validated);
    }

    protected function getOperationType(): string 
    {
        return 'create_content';
    }

    protected function getRequiredPermissions(): array 
    {
        return ['content.create'];
    }

    protected function getValidationRules(): array 
    {
        return [
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'status' => 'required|in:draft,published'
        ];
    }

    protected function getResultRules(): array 
    {
        return [
            'id' => 'required|numeric',
            'title' => 'required|string',
            'body' => 'required|string',
            'status' => 'required|string'
        ];
    }
}

class ContentRepository implements Repository
{
    private DatabaseManager $db;
    private CacheManager $cache;
    private ValidationService $validator;

    public function find(int $id): ?Content 
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->findInDatabase($id)
        );
    }

    public function create(array $data): Content 
    {
        $content = $this->db->create('contents', $data);
        $this->cache->invalidatePrefix($this->getCachePrefix());
        return $content;
    }

    public function update(int $id, array $data): Content 
    {
        $content = $this->db->update('contents', $id, $data);
        $this->cache->invalidate($this->getCacheKey('find', $id));
        return $content;
    }

    private function findInDatabase(int $id): ?Content 
    {
        return $this->db->table('contents')->find($id);
    }

    protected function getCachePrefix(): string 
    {
        return 'content';
    }

    private function getCacheKey(string $operation, ...$params): string 
    {
        return $this->getCachePrefix() . '.' . $operation . '.' . implode('.', $params);
    }
}
