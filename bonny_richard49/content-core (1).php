<?php

namespace App\Core\Content;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private EventDispatcher $events;

    public function createContent(array $data): ContentResult
    {
        // Create a critical operation
        $operation = new CreateContentOperation($data, $this->repository);

        // Execute with full security and validation
        return $this->security->executeCriticalOperation($operation);
    }

    public function updateContent(int $id, array $data): ContentResult
    {
        $operation = new UpdateContentOperation($id, $data, $this->repository);
        return $this->security->executeCriticalOperation($operation);
    }

    public function deleteContent(int $id): bool
    {
        $operation = new DeleteContentOperation($id, $this->repository);
        return $this->security->executeCriticalOperation($operation);
    }

    public function getContent(int $id): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey('content', $id),
            fn() => $this->repository->find($id)
        );
    }

    public function publishContent(int $id): bool
    {
        $operation = new PublishContentOperation($id, $this->repository);
        return $this->security->executeCriticalOperation($operation);
    }

    protected function getCacheKey(string $type, int $id): string
    {
        return "content:{$type}:{$id}";
    }
}

class CreateContentOperation implements CriticalOperation
{
    private array $data;
    private ContentRepository $repository;

    public function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ];
    }

    public function execute(): ContentResult
    {
        // Execute with transaction protection
        return $this->repository->create($this->data);
    }

    public function getRequiredPermissions(): array
    {
        return ['content.create'];
    }
}

class ContentRepository extends BaseRepository
{
    protected function getCacheKey(string $operation, ...$params): string
    {
        return match($operation) {
            'find' => "content:find:{$params[0]}",
            'list' => "content:list:{$params[0]}",
            default => "content:{$operation}"
        };
    }

    protected function getCacheConfig(): array
    {
        return [
            'ttl' => 3600,
            'tags' => ['content']
        ];
    }

    public function create(array $data): Content
    {
        $content = $this->model->create($this->validateData($data));
        $this->cache->tags('content')->flush();
        return $content;
    }

    public function update(int $id, array $data): Content
    {
        $content = $this->model->findOrFail($id);
        $content->update($this->validateData($data));
        $this->cache->tags('content')->flush();
        return $content;
    }
}

class ContentValidationService extends ValidationService
{
    public function validateContent(array $data): array
    {
        $rules = [
            'title' => [
                'required',
                'string',
                'max:200',
                new ContentTitleRule(),
            ],
            'content' => [
                'required',
                'string',
                new SafeHtmlRule(),
            ],
            'status' => [
                'required',
                Rule::in(['draft', 'published', 'archived']),
            ],
            'author_id' => [
                'required',
                'exists:users,id',
            ],
            'metadata' => 'array',
        ];

        return $this->validate($data, $rules);
    }

    public function validateUpdate(array $data): array
    {
        $rules = [
            'title' => [
                'sometimes',
                'string',
                'max:200',
                new ContentTitleRule(),
            ],
            'content' => [
                'sometimes',
                'string',
                new SafeHtmlRule(),
            ],
            'status' => [
                'sometimes',
                Rule::in(['draft', 'published', 'archived']),
            ],
            'metadata' => 'array',
        ];

        return $this->validate($data, $rules);
    }
}
