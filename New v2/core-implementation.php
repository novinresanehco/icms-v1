<?php

namespace App\Core\Content;

use App\Core\Interfaces\ContentRepositoryInterface;
use App\Core\Models\Content;
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;

class ContentRepository implements ContentRepositoryInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache, 
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(
            "content.$id",
            fn() => Content::find($id)
        );
    }

    public function store(array $data): Content
    {
        // Validate input
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'author_id' => 'required|exists:users,id'
        ]);

        // Encrypt sensitive data
        $validated['content'] = $this->security->encrypt($validated['content']);
        
        // Generate checksum
        $validated['checksum'] = $this->security->generateChecksum($validated);

        return DB::transaction(function() use ($validated) {
            $content = Content::create($validated);
            $this->cache->invalidate("content.{$content->id}");
            return $content;
        });
    }
}

class ContentService
{
    private ContentRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;

    public function __construct(
        ContentRepository $repository,
        SecurityManager $security,
        ValidationService $validator
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
    }

    public function createContent(array $data): Content
    {
        // Verify user permissions
        $this->security->validateAccess('content.create');

        // Store with audit logging
        $content = DB::transaction(function() use ($data) {
            $content = $this->repository->store($data);
            
            // Log the operation
            Log::info('Content created', [
                'id' => $content->id,
                'author' => $data['author_id']
            ]);

            return $content;
        });

        return $content;
    }

    public function getContent(int $id): Content
    {
        // Verify read permissions
        $this->security->validateAccess('content.read');

        $content = $this->repository->find($id);

        if (!$content) {
            throw new ContentNotFoundException("Content not found: $id");
        }

        // Verify data integrity
        if (!$this->security->verifyChecksum($content)) {
            throw new DataIntegrityException("Content integrity check failed: $id");
        }

        // Decrypt content for display
        $content->content = $this->security->decrypt($content->content);

        return $content;
    }
}
