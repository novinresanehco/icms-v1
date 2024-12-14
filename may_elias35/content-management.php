<?php

namespace App\Core\Content;

use App\Core\Security\CoreSecurityManager;
use App\Core\Content\Models\Content;
use App\Core\Content\Services\{
    ValidationService,
    CacheManager,
    MediaHandler,
    VersionManager
};
use App\Core\Exceptions\ContentException;
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagementInterface 
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private MediaHandler $media;
    private VersionManager $versions;

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        MediaHandler $media,
        VersionManager $versions
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->media = $media;
        $this->versions = $versions;
    }

    public function create(array $data): Content 
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->validator),
            $this->createContext('create')
        );
    }

    public function update(int $id, array $data): Content 
    {
        $operation = new UpdateContentOperation(
            $id, 
            $data,
            $this->validator,
            $this->versions
        );

        return $this->security->executeCriticalOperation(
            $operation,
            $this->createContext('update')
        );
    }

    public function delete(int $id): bool 
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id),
            $this->createContext('delete')
        );
    }

    public function publish(int $id): bool 
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id),
            $this->createContext('publish')
        );
    }

    public function find(int $id): ?Content 
    {
        return $this->cache->remember("content.$id", function() use ($id) {
            return Content::findOrFail($id);
        });
    }

    public function attachMedia(int $contentId, array $mediaIds): void 
    {
        $this->security->executeCriticalOperation(
            new AttachMediaOperation($contentId, $mediaIds, $this->media),
            $this->createContext('media')
        );
    }

    private function createContext(string $operation): SecurityContext 
    {
        return new SecurityContext(
            $operation,
            'content',
            auth()->user()
        );
    }
}

class CreateContentOperation implements CriticalOperation 
{
    private array $data;
    private ValidationService $validator;

    public function __construct(array $data, ValidationService $validator) 
    {
        $this->data = $data;
        $this->validator = $validator;
    }

    public function execute(): Content 
    {
        $validatedData = $this->validator->validate($this->data);
        
        return DB::transaction(function() use ($validatedData) {
            $content = Content::create($validatedData);
            
            if (isset($validatedData['tags'])) {
                $content->syncTags($validatedData['tags']);
            }

            return $content;
        });
    }

    public function getValidationRules(): array 
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'tags' => 'array'
        ];
    }

    public function getRequiredPermissions(): array 
    {
        return ['content.create'];
    }

    public function getRateLimitKey(): string 
    {
        return 'content.create.' . auth()->id();
    }
}

class UpdateContentOperation implements CriticalOperation 
{
    private int $id;
    private array $data;
    private ValidationService $validator;
    private VersionManager $versions;

    public function __construct(
        int $id,
        array $data,
        ValidationService $validator,
        VersionManager $versions
    ) {
        $this->id = $id;
        $this->data = $data;
        $this->validator = $validator;
        $this->versions = $versions;
    }

    public function execute(): Content 
    {
        $validatedData = $this->validator->validate($this->data);
        
        return DB::transaction(function() use ($validatedData) {
            $content = Content::findOrFail($this->id);
            
            $this->versions->createVersion($content);
            
            $content->update($validatedData);
            
            if (isset($validatedData['tags'])) {
                $content->syncTags($validatedData['tags']);
            }

            return $content;
        });
    }

    public function getValidationRules(): array 
    {
        return [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published',
            'tags' => 'array'
        ];
    }

    public function getRequiredPermissions(): array 
    {
        return ['content.update'];
    }

    public function getRateLimitKey(): string 
    {
        return 'content.update.' . $this->id;
    }
}

class DeleteContentOperation implements CriticalOperation 
{
    private int $id;

    public function __construct(int $id) 
    {
        $this->id = $id;
    }

    public function execute(): bool 
    {
        return DB::transaction(function() {
            $content = Content::findOrFail($this->id);
            return $content->delete();
        });
    }

    public function getValidationRules(): array 
    {
        return [];
    }

    public function getRequiredPermissions(): array 
    {
        return ['content.delete'];
    }

    public function getRateLimitKey(): string 
    {
        return 'content.delete.' . $this->id;
    }
}
