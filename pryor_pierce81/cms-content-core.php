<?php

namespace App\Core\Content;

class ContentManager implements ContentInterface
{
    private Repository $repo;
    private ValidationService $validator; 
    private VersionControl $versions;
    private CacheManager $cache;

    public function create(array $data): Content
    {
        return DB::transaction(function() use ($data) {
            // Validate
            $this->validator->validateContent($data);
            
            // Create
            $content = $this->repo->create($data);
            
            // Version
            $this->versions->createInitial($content);
            
            // Cache
            $this->cache->put($content);
            
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            // Load
            $content = $this->repo->findOrFail($id);
            
            // Validate
            $this->validator->validateContent($data);
            
            // Version
            $this->versions->createVersion($content);
            
            // Update
            $content = $this->repo->update($id, $data);
            
            // Cache
            $this->cache->put($content);
            
            return $content;
        });
    }
}

class VersionControl
{
    private Repository $versions;
    private DiffGenerator $differ;

    public function createVersion(Content $content): void
    {
        // Generate diff
        $diff = $this->differ->generateDiff($content);
        
        // Store version
        $this->versions->create([
            'content_id' => $content->id,
            'diff' => $diff,
            'created_at' => now()
        ]);
    }
}

class ValidationService
{
    private array $rules = [];
    
    public function validateContent(array $data): void
    {
        // Structure validation
        $this->validateStructure($data);
        
        // Content validation
        $this->validateContentTypes($data);
        
        // Media validation
        if (isset($data['media'])) {
            $this->validateMedia($data['media']);
        }
    }
}
