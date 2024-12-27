<?php

namespace App\Core\Content;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private Repository $repository;
    private CacheManager $cache;
    private EventDispatcher $events;
    
    /**
     * Store content with comprehensive validation and security
     */
    public function store(array $data, SecurityContext $context): Content
    {
        // Create critical operation
        $operation = new StoreContentOperation($data);
        
        // Execute with full security
        return $this->security->executeCriticalOperation($operation, $context);
    }
    
    /**
     * Retrieve content with security checks and caching
     */
    public function retrieve(int $id, SecurityContext $context): Content
    {
        return $this->cache->remember(['content', $id], function() use ($id, $context) {
            // Create critical operation
            $operation = new RetrieveContentOperation($id);
            
            // Execute with security
            return $this->security->executeCriticalOperation($operation, $context);
        });
    }
    
    /**
     * Update content with validation and security
     */
    public function update(int $id, array $data, SecurityContext $context): Content
    {
        // Create critical operation
        $operation = new UpdateContentOperation($id, $data);
        
        // Execute with security
        $result = $this->security->executeCriticalOperation($operation, $context);
        
        // Clear cache
        $this->cache->invalidate(['content', $id]);
        
        return $result;
    }
    
    /**
     * Delete content with security checks
     */
    public function delete(int $id, SecurityContext $context): bool
    {
        // Create critical operation
        $operation = new DeleteContentOperation($id);
        
        // Execute with security
        $result = $this->security->executeCriticalOperation($operation, $context);
        
        // Clear cache
        $this->cache->invalidate(['content', $id]);
        
        return $result;
    }
}

// Critical Operation Classes
class StoreContentOperation extends CriticalOperation
{
    private array $data;
    
    public function __construct(array $data)
    {
        $this->data = $data;
    }
    
    public function execute(): Content
    {
        return $this->repository->store($this->data);
    }
    
    public function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ];
    }
    
    public function getRequiredPermissions(): array
    {
        return ['content.create'];
    }
}
