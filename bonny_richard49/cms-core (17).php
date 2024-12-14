<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\CMS\Services\{
    ContentService,
    ValidationService,
    CacheService
};
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private ContentService $content;
    private ValidationService $validator;
    private CacheService $cache;

    public function __construct(
        SecurityManager $security,
        ContentService $content,
        ValidationService $validator,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function create(array $data, SecurityContext $context): ContentResult 
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data),
            $context
        );
    }

    public function update(int $id, array $data, SecurityContext $context): ContentResult 
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data),
            $context
        );
    }

    public function delete(int $id, SecurityContext $context): bool 
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id),
            $context
        );
    }

    public function publish(int $id, SecurityContext $context): bool 
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id),
            $context
        );
    }
}

abstract class ContentOperation implements CriticalOperation 
{
    protected array $data;
    protected ValidationService $validator;
    protected ContentService $content;
    protected CacheService $cache;

    public function getValidationRules(): array 
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id'
        ];
    }

    public function getSecurityRequirements(): array 
    {
        return [
            'content.create',
            'content.manage',
            'system.access'
        ];
    }

    abstract public function execute(): ContentResult;
}

class CreateContentOperation extends ContentOperation 
{
    public function execute(): ContentResult 
    {
        DB::beginTransaction();
        
        try {
            // Validate input
            $validatedData = $this->validator->validate($this->data);
            
            // Create content
            $content = $this->content->create($validatedData);
            
            // Clear relevant caches
            $this->cache->invalidateContentCaches();
            
            DB::commit();
            return new ContentResult($content);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

class UpdateContentOperation extends ContentOperation 
{
    private int $id;

    public function __construct(int $id, array $data) 
    {
        $this->id = $id;
        $this->data = $data;
    }

    public function execute(): ContentResult 
    {
        DB::beginTransaction();
        
        try {
            // Verify content exists
            $content = $this->content->findOrFail($this->id);
            
            // Validate updates
            $validatedData = $this->validator->validate($this->data);
            
            // Update content
            $updated = $this->content->update($content, $validatedData);
            
            // Clear caches
            $this->cache->invalidateContentCaches($this->id);
            
            DB::commit();
            return new ContentResult($updated);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

class PublishContentOperation extends ContentOperation 
{
    private int $id;

    public function execute(): ContentResult 
    {
        DB::beginTransaction();
        
        try {
            // Verify content
            $content = $this->content->findOrFail($this->id);
            
            // Additional publish validations
            $this->validator->validateForPublishing($content);
            
            // Publish content
            $published = $this->content->publish($content);
            
            // Clear caches
            $this->cache->invalidateContentCaches($this->id);
            
            DB::commit();
            return new ContentResult($published);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

class ContentRepository implements ContentRepositoryInterface 
{
    private SecurityManager $security;
    
    public function findById(int $id): ?Content 
    {
        return $this->security->executeQuery(
            fn() => Content::find($id)
        );
    }
    
    public function save(Content $content): Content 
    {
        return $this->security->executeCommand(
            fn() => $content->save()
        );
    }
    
    public function delete(Content $content): bool 
    {
        return $this->security->executeCommand(
            fn() => $content->delete()
        );
    }
}

class ContentService
{
    private ContentRepository $repository;
    private ValidationService $validator;
    private CacheService $cache;

    public function create(array $data): Content
    {
        $content = new Content($data);
        return $this->repository->save($content);
    }

    public function update(Content $content, array $data): Content
    {
        $content->fill($data);
        return $this->repository->save($content);
    }

    public function publish(Content $content): Content
    {
        $content->status = 'published';
        $content->published_at = now();
        return $this->repository->save($content);
    }

    public function findOrFail(int $id): Content
    {
        return $this->cache->remember(
            "content.{$id}",
            fn() => $this->repository->findById($id)
        ) ?? throw new ContentNotFoundException();
    }
}

class ValidationService 
{
    public function validate(array $data): array
    {
        $validator = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['required', 'in:draft,published'],
            'meta' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    public function validateForPublishing(Content $content): void
    {
        $validator = Validator::make($content->toArray(), [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'min:100'],
            'category_id' => ['required', 'exists:categories,id'],
            'meta_description' => ['required', 'string', 'max:160'],
            'meta_keywords' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }
    }
}

class CacheService 
{
    private Cache $cache;
    private int $ttl = 3600;

    public function remember(string $key, callable $callback)
    {
        return $this->cache->remember($key, $this->ttl, $callback);
    }

    public function invalidateContentCaches(int $id = null): void
    {
        if ($id) {
            $this->cache->forget("content.{$id}");
        }
        $this->cache->tags(['content'])->flush();
    }
}
