<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache, Storage};
use App\Core\Security\SecurityManager;
use App\Core\CMS\Events\ContentEvent;

class ContentManager implements ContentManagerInterface 
{
    private SecurityManager $security;
    private AuditLogger $auditLogger;
    private MediaManager $mediaManager;
    private CategoryManager $categoryManager;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        AuditLogger $auditLogger,
        MediaManager $mediaManager,
        CategoryManager $categoryManager,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->auditLogger = $auditLogger;
        $this->mediaManager = $mediaManager;
        $this->categoryManager = $categoryManager;
        $this->validator = $validator;
    }

    public function createContent(ContentRequest $request): ContentResult 
    {
        return DB::transaction(function() use ($request) {
            try {
                // Validate request and permissions
                $this->validateContentRequest($request);
                $this->security->validateAccess($request);

                // Process and store content
                $content = $this->processContent($request);
                $content = $this->storeContent($content);

                // Handle relationships and media
                $this->processRelationships($content, $request);
                $this->processMediaAttachments($content, $request);

                // Cache new content
                $this->cacheContent($content);

                // Log success
                $this->auditLogger->logContentCreation($content, $request);

                return new ContentResult($content);

            } catch (Exception $e) {
                $this->handleContentError($e, $request);
                throw $e;
            }
        });
    }

    private function validateContentRequest(ContentRequest $request): void 
    {
        $validated = $this->validator->validate($request, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'categories' => 'array',
            'media' => 'array',
            'meta' => 'array'
        ]);

        if (!$validated) {
            throw new ValidationException('Invalid content request');
        }
    }

    private function processContent(ContentRequest $request): Content 
    {
        // Sanitize content
        $content = new Content([
            'title' => $this->sanitizeTitle($request->title),
            'content' => $this->sanitizeContent($request->content),
            'status' => $request->status,
            'slug' => $this->generateUniqueSlug($request->title),
            'meta' => $this->processMetaData($request->meta)
        ]);

        // Version the content
        $content->version = $this->createVersion($content);

        return $content;
    }

    private function storeContent(Content $content): Content 
    {
        $content->save();

        // Process full-text search indexing
        $this->indexContent($content);

        return $content;
    }

    private function processRelationships(Content $content, ContentRequest $request): void 
    {
        // Handle categories
        if ($request->has('categories')) {
            $this->categoryManager->attachCategories($content, $request->categories);
        }

        // Handle parent-child relationships
        if ($request->has('parent_id')) {
            $this->handleContentHierarchy($content, $request->parent_id);
        }
    }

    private function processMediaAttachments(Content $content, ContentRequest $request): void 
    {
        if ($request->has('media')) {
            foreach ($request->media as $mediaItem) {
                $this->mediaManager->processAndAttach($content, $mediaItem);
            }
        }
    }

    private function cacheContent(Content $content): void 
    {
        $cacheKey = "content:{$content->id}";
        Cache::put($cacheKey, $content, now()->addHours(24));

        // Cache related data
        $this->cacheRelatedData($content);
    }

    private function cacheRelatedData(Content $content): void 
    {
        // Cache categories
        $categoryCacheKey = "content_categories:{$content->id}";
        Cache::put($categoryCacheKey, $content->categories, now()->addHours(24));

        // Cache media
        $mediaCacheKey = "content_media:{$content->id}";
        Cache::put($mediaCacheKey, $content->media, now()->addHours(24));
    }

    private function createVersion(Content $content): ContentVersion 
    {
        return new ContentVersion([
            'content' => $content,
            'version_number' => $this->getNextVersionNumber($content),
            'created_by' => auth()->id(),
            'created_at' => now()
        ]);
    }

    private function indexContent(Content $content): void 
    {
        // Add to search index
        $searchData = [
            'title' => $content->title,
            'content' => strip_tags($content->content),
            'categories' => $content->categories->pluck('name'),
            'status' => $content->status,
            'created_at' => $content->created_at
        ];

        $this->searchIndex->index($content->id, $searchData);
    }

    private function handleContentError(Exception $e, ContentRequest $request): void 
    {
        $this->auditLogger->logContentError($e, $request);

        if ($e instanceof SecurityException) {
            event(new ContentSecurityEvent($e, $request));
        }
    }

    private function sanitizeTitle(string $title): string 
    {
        return strip_tags(trim($title));
    }

    private function sanitizeContent(string $content): string 
    {
        return $this->purifier->purify($content);
    }

    private function generateUniqueSlug(string $title): string 
    {
        $slug = str_slug($title);
        $originalSlug = $slug;
        $count = 1;

        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $count++;
        }

        return $slug;
    }

    private function slugExists(string $slug): bool 
    {
        return DB::table('content')->where('slug', $slug)->exists();
    }

    private function processMetaData(array $meta): array 
    {
        return array_map(function($value) {
            return strip_tags(trim($value));
        }, $meta);
    }
}
