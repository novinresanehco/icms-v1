<?php

namespace App\Core\CMS;

class ContentManagementSystem implements CMSInterface
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private ValidationService $validator;
    private AuditService $audit;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        ValidationService $validator,
        AuditService $audit,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->cache = $cache;
    }

    public function createContent(array $data): ContentResult
    {
        return $this->security->executeSecureOperation(function() use ($data) {
            // Validate input
            $validatedData = $this->validator->validateContentData($data);
            
            // Create content
            $content = $this->repository->create($validatedData);
            
            // Clear cache
            $this->cache->invalidateContentCache();
            
            // Audit log
            $this->audit->logContentCreation($content);
            
            return new ContentResult($content);
        });
    }

    public function updateContent(int $id, array $data): ContentResult
    {
        return $this->security->executeSecureOperation(function() use ($id, $data) {
            // Validate content exists
            $content = $this->repository->findOrFail($id);
            
            // Validate update data
            $validatedData = $this->validator->validateContentData($data);
            
            // Update content
            $updated = $this->repository->update($content, $validatedData);
            
            // Clear cache
            $this->cache->invalidateContentCache($id);
            
            // Audit log
            $this->audit->logContentUpdate($updated);
            
            return new ContentResult($updated);
        });
    }

    public function publishContent(int $id): ContentResult
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            // Validate content
            $content = $this->repository->findOrFail($id);
            
            // Additional publish validation
            $this->validator->validateForPublishing($content);
            
            // Publish content
            $published = $this->repository->publish($content);
            
            // Clear cache
            $this->cache->invalidateContentCache($id);
            
            // Audit log
            $this->audit->logContentPublication($published);
            
            return new ContentResult($published);
        });
    }

    public function deleteContent(int $id): bool
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            // Validate content exists
            $content = $this->repository->findOrFail($id);
            
            // Delete content
            $this->repository->delete($content);
            
            // Clear cache
            $this->cache->invalidateContentCache($id);
            
            // Audit log
            $this->audit->logContentDeletion($content);
            
            return true;
        });
    }
}

class ContentRepository implements RepositoryInterface
{
    private Database $db;
    private ValidationService $validator;
    
    public function findOrFail(int $id): Content
    {
        $content = $this->db->table('content')->find($id);
        
        if (!$content) {
            throw new ContentNotFoundException("Content not found: {$id}");
        }
        
        return new Content($content);
    }
    
    public function create(array $data): Content
    {
        $this->validator->validateContentData($data);
        
        $content = $this->db->table('content')->create($data);
        
        return new Content($content);
    }
    
    public function update(Content $content, array $data): Content
    {
        $this->validator->validateContentData($data);
        
        $updated = $this->db->table('content')
            ->where('id', $content->getId())
            ->update($data);
            
        return new Content($updated);
    }
    
    public function delete(Content $content): bool
    {
        return $this->db->table('content')
            ->where('id', $content->getId())
            ->delete();
    }
    
    public function publish(Content $content): Content
    {
        $published = $this->db->table('content')
            ->where('id', $content->getId())
            ->update([
                'status' => 'published',
                'published_at' => now()
            ]);
            
        return new Content($published);
    }
}

class ValidationService
{
    public function validateContentData(array $data): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['required', 'in:draft,published'],
            'author_id' => ['required', 'exists:users,id'],
            'category_id' => ['required', 'exists:categories,id']
        ];
        
        $validator = Validator::make($data, $rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }
        
        return $validator->validated();
    }
    
    public function validateForPublishing(Content $content): void
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'min:100'],
            'category_id' => ['required', 'exists:categories,id'],
            'meta_description' => ['required', 'string', 'max:160'],
            'meta_keywords' => ['required', 'string', 'max:255']
        ];
        
        $validator = Validator::make($content->toArray(), $rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }
    }
}

class ContentResult
{
    private Content $content;
    
    public function __construct(Content $content)
    {
        $this->content = $content;
    }
    
    public function getContent(): Content
    {
        return $this->content;
    }
}

class Content
{
    private array $attributes;
    
    public function __construct(array $attributes)
    {
        $this->attributes = $attributes;
    }
    
    public function getId(): int
    {
        return $this->attributes['id'];
    }
    
    public function toArray(): array
    {
        return $this->attributes;
    }
}

