<?php

namespace App\Core\CMS;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function createContent(array $data): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation(
                $data,
                $this->repository,
                $this->validator,
                $this->cache
            )
        );
    }

    public function updateContent(int $id, array $data): ContentResult
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation(
                $id, 
                $data,
                $this->repository,
                $this->validator,
                $this->cache
            )
        );
    }

    public function publishContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation(
                $id,
                $this->repository,
                $this->cache
            )
        );
    }

    public function getContent(int $id): ?Content
    {
        return $this->cache->remember(
            "content.$id",
            fn() => $this->repository->find($id)
        );
    }
}

abstract class ContentOperation implements CriticalOperation
{
    protected Repository $repository;
    protected ValidationService $validator;
    protected CacheManager $cache;
    protected array $data;

    public function getValidationRules(): array
    {
        return [
            'title' => 'required|max:200',
            'content' => 'required',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['content.manage'];
    }

    protected function invalidateCache(int $id): void
    {
        $this->cache->tags(['content'])->forget("content.$id");
    }

    abstract public function execute(): ContentResult;
}

class CreateContentOperation extends ContentOperation
{
    public function __construct(
        array $data,
        Repository $repository,
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->data = $data;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function execute(): ContentResult
    {
        $validated = $this->validator->validate(
            $this->data, 
            $this->getValidationRules()
        );

        $content = $this->repository->create($validated);
        $this->invalidateCache($content->id);

        return new ContentResult($content);
    }
}

class ContentRepository implements Repository
{
    private DB $database;
    private ValidationService $validator;

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            $content = $this->database->table('contents')->create($data);
            $this->createRevision($content);
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->find($id);
            $content->update($data);
            $this->createRevision($content);
            return $content;
        });
    }

    public function find(int $id): ?Content
    {
        return $this->database->table('contents')
            ->with(['author', 'category'])
            ->find($id);
    }

    private function createRevision(Content $content): void
    {
        $this->database->table('content_revisions')->create([
            'content_id' => $content->id,
            'data' => json_encode($content->toArray()),
            'created_at' => now()
        ]);
    }
}

class ContentResult
{
    private Content $content;
    private array $meta;

    public function __construct(Content $content, array $meta = [])
    {
        $this->content = $content;
        $this->meta = $meta;
    }

    public function getContent(): Content
    {
        return $this->content;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function isValid(): bool
    {
        return $this->content->exists;
    }
}
