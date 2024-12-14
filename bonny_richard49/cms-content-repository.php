<?php

namespace App\Core\CMS\Repository;

use App\Core\Database\BaseRepository;
use App\Core\CMS\Models\Content;

class ContentRepository extends BaseRepository
{
    private SecurityManager $security;
    private CacheManager $cache;
    private VersionManager $versions;
    private array $config;

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id'
        ];
    }

    protected function beforeCreate(array $data): array
    {
        // Add version info
        $data['version'] = 1;
        $data['created_by'] = auth()->id();
        
        // Generate slug
        $data['slug'] = $this->generateUniqueSlug($data['title']);

        // Security checks
        $this->security->validateContentCreation($data);

        return $data;
    }

    protected function afterCreate(Content $content): void
    {
        // Create initial version
        $this->versions->createVersion($content);

        // Clear relevant caches
        $this->cache->tags(['content'])->flush();

        // Index for search
        $this->indexContent($content);

        // Log creation
        $this->logContentOperation('create', $content);
    }

    protected function beforeUpdate(Content $content, array $data): array
    {
        // Increment version
        $data['version'] = $content->version + 1;
        $data['updated_by'] = auth()->id();

        // Update slug if title changed
        if (isset($data['title']) && $data['title'] !== $content->title) {
            $data['slug'] = $this->generateUniqueSlug($data['title']);
        }

        // Security checks
        $this->security->validateContentUpdate($content, $data);

        return $data;
    }

    protected function afterUpdate(Content $content): void
    {
        // Create new version
        $this->versions->createVersion($content);

        // Clear relevant caches
        $this->cache->tags(['content', "content:{$content->id}"])->flush();

        // Update search index
        $this->updateContentIndex($content);

        // Log update
        $this->logContentOperation('update', $content);
    }

    protected function beforeDelete(Content $content): void
    {
        // Security checks
        $this->security->validateContentDeletion($content);

        // Check dependencies
        if ($this->hasCriticalDependencies($content)) {
            throw new ContentDependencyException('Content has critical dependencies');
        }
    }

    protected function afterDelete(Content $content): void
    {
        // Archive versions
        $this->versions->archiveVersions($content);

        // Clear caches
        $this->cache->tags(['content', "content:{$content->id}"])->flush();

        // Remove from search index
        $this->removeFromIndex($content);

        // Log deletion
        $this->logContentOperation('delete', $content);
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->cache->tags(['content'])->remember(
            "content:slug:{$slug}",
            $this->config['cache_ttl'],
            fn() => $this->model->where('slug', $slug)->first()
        );
    }

    public function getPublished(array $criteria = []): Collection
    {
        $query = $this->model->where('status', 'published');

        if (isset($criteria['category_id'])) {
            $query->where('category_id', $criteria['category_id']);
        }

        if (isset($criteria['author_id'])) {
            $query->where('author_id', $criteria['author_id']);
        }

        return $this->cache->tags(['content'])->remember(
            $this->getCacheKey('published', $criteria),
            $this->config['cache_ttl'],
            fn() => $query->get()
        );
    }

    public function hasDependencies(int $contentId): bool
    {
        return $this->getDependencies($contentId)->isNotEmpty();
    }

    public function syncTags(int $contentId, array $tags): void
    {
        $content = $this->findOrFail($contentId);
        $content->tags()->sync($tags);
        
        $this->cache->tags(['content', "content:{$contentId}"])->flush();
    }

    protected function generateUniqueSlug(string $title): string
    {
        $slug = str_slug($title);
        $count = 0;

        while ($this->slugExists($slug, $count)) {
            $count++;
            $slug = str_slug($title) . '-' . $count;
        }

        return $slug;
    }

    protected function slugExists(string $slug, int $count = 0): bool
    {
        $finalSlug = $count === 0 ? $slug : "{$slug}-{$count}";
        return $this->model->where('slug', $finalSlug)->exists();
    }

    protected function hasCriticalDependencies(Content $content): bool
    {
        return $this->getDependencies($content->id)
                    ->filter(fn($dep) => $dep->is_critical)
                    ->isNotEmpty();
    }

    protected function indexContent(Content $content): void
    {
        // Index for search - implementation depends on search service used
    }

    protected function updateContentIndex(Content $content): void
    {
        // Update search index
    }

    protected function removeFromIndex(Content $content): void
    {
        // Remove from search index
    }

    protected function logContentOperation(string $operation, Content $content): void
    {
        $this->logger->log([
            'type' => 'content_operation',
            'operation' => $operation,
            'content_id' => $content->id,
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
    }

    protected function getCacheKey(string $type, array $criteria = []): string
    {
        return "content:{$type}:" . md5(serialize($criteria));
    }
}
