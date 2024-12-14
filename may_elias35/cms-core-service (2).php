<?php

namespace App\Core\Content;

class ContentManagementService implements ContentManagementInterface 
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $logger;
    
    public function create(array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation(
                $this->repository,
                $this->validator,
                $this->cache,
                $data
            ),
            SecurityContext::fromCurrentRequest()
        );
    }

    public function update(int $id, array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation(
                $this->repository,
                $this->validator,
                $this->cache,
                $id,
                $data
            ),
            SecurityContext::fromCurrentRequest()
        );
    }

    public function delete(int $id): bool 
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation(
                $this->repository,
                $this->cache,
                $id
            ),
            SecurityContext::fromCurrentRequest()
        );
    }

    public function publish(int $id): bool 
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation(
                $this->repository,
                $this->cache,
                $id
            ),
            SecurityContext::fromCurrentRequest()
        );
    }

    public function versionContent(int $id): ContentVersion 
    {
        return $this->security->executeCriticalOperation(
            new VersionContentOperation(
                $this->repository,
                $this->cache,
                $id
            ),
            SecurityContext::fromCurrentRequest()
        );
    }

    private function validateContent(array $data): array 
    {
        return $this->validator->validate($data, [
            'title' => 'required|max:200',
            'content' => 'required',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id'
        ]);
    }
}

class CreateContentOperation implements CriticalOperation 
{
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheManager $cache;
    private array $data;

    public function execute(): Content 
    {
        $validated = $this->validator->validate($this->data);
        $content = $this->repository->create($validated);
        $this->cache->invalidateContentCache();
        return $content;
    }

    public function getValidationRules(): array 
    {
        return ContentValidationRules::getCreationRules();
    }
}

class ContentRepository 
{
    private DB $db;
    private CacheManager $cache;

    public function find(int $id): ?Content 
    {
        return $this->cache->remember(
            $this->getCacheKey($id),
            fn() => $this->db->table('contents')->find($id)
        );
    }

    public function create(array $data): Content 
    {
        $content = $this->db->transaction(function() use ($data) {
            $content = Content::create($data);
            $this->createVersion($content);
            return $content;
        });

        $this->cache->invalidateContentCache();
        return $content;
    }

    public function update(int $id, array $data): Content 
    {
        return $this->db->transaction(function() use ($id, $data) {
            $content = $this->find($id);
            $content->update($data);
            $this->createVersion($content);
            $this->cache->invalidateContentCache();
            return $content;
        });
    }

    private function createVersion(Content $content): ContentVersion 
    {
        return ContentVersion::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id()
        ]);
    }

    private function getCacheKey(int $id): string 
    {
        return "content.{$id}";
    }
}

class ContentValidationRules 
{
    public static function getCreationRules(): array 
    {
        return [
            'title' => 'required|max:200',
            'content' => 'required',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id',
            'publish_at' => 'nullable|date|after:now',
            'meta' => 'array',
            'meta.description' => 'nullable|max:500',
            'meta.keywords' => 'nullable|max:200'
        ];
    }

    public static function getUpdateRules(): array 
    {
        return [
            'title' => 'sometimes|required|max:200',
            'content' => 'sometimes|required',
            'status' => 'sometimes|required|in:draft,published',
            'category_id' => 'sometimes|required|exists:categories,id',
            'tags' => 'sometimes|array',
            'tags.*' => 'exists:tags,id',
            'publish_at' => 'nullable|date|after:now',
            'meta' => 'sometimes|array',
            'meta.description' => 'nullable|max:500',
            'meta.keywords' => 'nullable|max:200'
        ];
    }
}

interface ContentManagementInterface 
{
    public function create(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
    public function publish(int $id): bool;
    public function versionContent(int $id): ContentVersion;
}
