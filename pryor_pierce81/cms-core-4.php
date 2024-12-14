<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Interfaces\{ContentManagerInterface, CacheManagerInterface};
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private CacheManagerInterface $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        CacheManagerInterface $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function createContent(array $data): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeCreate($data),
            new SecurityContext('content.create', $data)
        );
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeUpdate($id, $data),
            new SecurityContext('content.update', ['id' => $id, 'data' => $data])
        );
    }

    public function getContent(int $id): Content
    {
        return $this->cache->remember(
            "content.$id",
            fn() => $this->loadContent($id)
        );
    }

    public function deleteContent(int $id): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeDelete($id),
            new SecurityContext('content.delete', ['id' => $id])
        );
    }

    protected function executeCreate(array $data): Content
    {
        DB::beginTransaction();
        try {
            $validatedData = $this->validateContentData($data);
            $content = Content::create($validatedData);
            
            $this->processContentRelations($content, $data);
            $this->indexContentForSearch($content);
            
            $this->audit->logContentCreation($content);
            
            DB::commit();
            $this->cache->clearContentCache();
            
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content creation failed: ' . $e->getMessage());
        }
    }

    protected function executeUpdate(int $id, array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = $this->getContentForUpdate($id);
            $validatedData = $this->validateContentData($data);
            
            $this->createContentVersion($content);
            $content->update($validatedData);
            
            $this->updateContentRelations($content, $data);
            $this->updateContentIndex($content);
            
            $this->audit->logContentUpdate($content);
            
            DB::commit();
            $this->cache->clearContentCache($id);
            
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content update failed: ' . $e->getMessage());
        }
    }

    protected function executeDelete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $content = $this->getContentForDelete($id);
            
            $this->createContentArchive($content);
            $this->removeContentRelations($content);
            $this->removeFromSearch($content);
            
            $content->delete();
            
            $this->audit->logContentDeletion($content);
            
            DB::commit();
            $this->cache->clearContentCache($id);
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentException('Content deletion failed: ' . $e->getMessage());
        }
    }

    protected function validateContentData(array $data): array
    {
        $rules = $this->config['validation_rules'];
        if (!$this->validator->validateData($data, $rules)) {
            throw new ValidationException('Invalid content data');
        }
        return $this->sanitizeContentData($data);
    }

    protected function getContentForUpdate(int $id): Content
    {
        $content = Content::lockForUpdate()->find($id);
        if (!$content) {
            throw new ContentException('Content not found');
        }
        return $content;
    }

    protected function createContentVersion(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'data' => $content->toArray(),
            'created_by' => auth()->id(),
            'created_at' => now()
        ]);
    }

    protected function indexContentForSearch(Content $content): void
    {
        if ($this->config['search_indexing_enabled']) {
            SearchIndexer::index($content);
        }
    }

    protected function processContentRelations(Content $content, array $data): void
    {
        if (isset($data['categories'])) {
            $content->categories()->sync($data['categories']);
        }
        if (isset($data['tags'])) {
            $content->tags()->sync($data['tags']);
        }
        if (isset($data['media'])) {
            $this->processMediaAttachments($content, $data['media']);
        }
    }

    protected function sanitizeContentData(array $data): array
    {
        return array_merge($data, [
            'slug' => $this->generateUniqueSlug($data['title']),
            'created_by' => auth()->id(),
            'html_content' => $this->sanitizeHtml($data['content']),
            'metadata' => $this->processMetadata($data['metadata'] ?? [])
        ]);
    }

    protected function processMediaAttachments(Content $content, array $media): void
    {
        foreach ($media as $item) {
            if ($this->validator->validateMediaItem($item)) {
                $content->media()->attach($item['id'], [
                    'type' => $item['type'],
                    'order' => $item['order'] ?? 0
                ]);
            }
        }
    }

    protected function generateUniqueSlug(string $title): string
    {
        $baseSlug = str_slug($title);
        $slug = $baseSlug;
        $counter = 1;

        while (Content::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        return $slug;
    }

    protected function sanitizeHtml(string $content): string
    {
        return clean($content, [
            'HTML.Allowed' => $this->config['allowed_html_tags'],
            'CSS.AllowedProperties' => $this->config['allowed_css_properties'],
            'AutoFormat.RemoveEmpty' => true,
        ]);
    }
}
