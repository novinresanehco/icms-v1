<?php

namespace App\Services;

use App\Interfaces\SecurityServiceInterface;
use App\Models\{Content, ContentVersion, Media};
use App\Exceptions\{ContentException, ValidationException};
use Illuminate\Support\Facades\{DB, Cache};

class ContentManagementService
{
    private SecurityServiceInterface $security;
    private ValidationService $validator;
    private AuditService $audit;
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        SecurityServiceInterface $security,
        ValidationService $validator,
        AuditService $audit
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function createContent(array $data): Content
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeCreateContent($data),
            ['action' => 'content.create', 'permission' => 'content.create']
        );
    }

    private function executeCreateContent(array $data): Content
    {
        $validatedData = $this->validator->validate($data, [
            'title' => 'required|max:255',
            'content' => 'required',
            'status' => 'required|in:draft,published',
            'type' => 'required|in:page,post,article'
        ]);

        return DB::transaction(function() use ($validatedData) {
            // Create content
            $content = Content::create($validatedData);
            
            // Create initial version
            $this->createVersion($content, $validatedData);
            
            // Clear cache
            $this->clearContentCache();
            
            return $content->fresh();
        });
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeUpdateContent($id, $data),
            ['action' => 'content.update', 'permission' => 'content.update']
        );
    }

    private function executeUpdateContent(int $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        
        $validatedData = $this->validator->validate($data, [
            'title' => 'sometimes|required|max:255',
            'content' => 'sometimes|required',
            'status' => 'sometimes|required|in:draft,published',
            'type' => 'sometimes|required|in:page,post,article'
        ]);

        return DB::transaction(function() use ($content, $validatedData) {
            // Create new version before updating
            $this->createVersion($content, $content->toArray());
            
            // Update content
            $content->update($validatedData);
            
            // Clear cache
            $this->clearContentCache($content->id);
            
            return $content->fresh();
        });
    }

    public function publishContent(int $id): Content
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executePublishContent($id),
            ['action' => 'content.publish', 'permission' => 'content.publish']
        );
    }

    private function executePublishContent(int $id): Content
    {
        return DB::transaction(function() use ($id) {
            $content = Content::findOrFail($id);
            
            // Create version before publishing
            $this->createVersion($content, $content->toArray());
            
            $content->status = 'published';
            $content->published_at = now();
            $content->save();
            
            // Clear cache
            $this->clearContentCache($content->id);
            
            return $content->fresh();
        });
    }

    public function getContent(int $id): Content
    {
        return Cache::remember(
            "content.{$id}",
            self::CACHE_TTL,
            fn() => Content::findOrFail($id)
        );
    }

    public function getVersions(int $id): Collection
    {
        return ContentVersion::where('content_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function revertToVersion(int $contentId, int $versionId): Content
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeRevertToVersion($contentId, $versionId),
            ['action' => 'content.revert', 'permission' => 'content.revert']
        );
    }

    private function executeRevertToVersion(int $contentId, int $versionId): Content
    {
        return DB::transaction(function() use ($contentId, $versionId) {
            $content = Content::findOrFail($contentId);
            $version = ContentVersion::where('content_id', $contentId)
                ->where('id', $versionId)
                ->firstOrFail();

            // Create version of current state before reverting
            $this->createVersion($content, $content->toArray());
            
            // Revert content to version data
            $versionData = json_decode($version->content_data, true);
            $content->update($versionData);
            
            // Clear cache
            $this->clearContentCache($content->id);
            
            return $content->fresh();
        });
    }

    private function createVersion(Content $content, array $data): ContentVersion
    {
        return ContentVersion::create([
            'content_id' => $content->id,
            'content_data' => json_encode($data),
            'created_by' => auth()->id()
        ]);
    }

    private function clearContentCache(int $id = null): void
    {
        if ($id) {
            Cache::forget("content.{$id}");
        } else {
            // Clear all content cache
            Cache::tags(['content'])->flush();
        }
    }
}
