<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Services\{CacheManager, ValidationService};
use App\Core\Interfaces\{ContentManagerInterface, StorageInterface};
use Illuminate\Support\Facades\DB;

class ContentManager implements ContentManagerInterface
{
    private SecurityManager $security;
    private StorageInterface $storage;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        StorageInterface $storage,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleCreate($data),
            ['action' => 'create_content', 'data' => $data]
        );
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleUpdate($id, $data),
            ['action' => 'update_content', 'id' => $id, 'data' => $data]
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handleDelete($id),
            ['action' => 'delete_content', 'id' => $id]
        );
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->handlePublish($id),
            ['action' => 'publish_content', 'id' => $id]
        );
    }

    public function find(int $id): ?Content
    {
        return $this->cache->remember(
            "content.{$id}",
            fn() => $this->storage->find($id)
        );
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->cache->remember(
            "content.slug.{$slug}",
            fn() => $this->storage->findBySlug($slug)
        );
    }

    private function handleCreate(array $data): Content
    {
        // Validate data
        $validatedData = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'type' => 'required|string'
        ]);

        // Generate slug
        $validatedData['slug'] = $this->generateUniqueSlug($validatedData['title']);

        // Create content
        $content = $this->storage->create($validatedData);

        // Clear relevant caches
        $this->clearContentCaches();

        return $content;
    }

    private function handleUpdate(int $id, array $data): Content
    {
        // Validate data
        $validatedData = $this->validator->validate($data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published',
            'type' => 'string'
        ]);

        // Update slug if title changes
        if (isset($validatedData['title'])) {
            $validatedData['slug'] = $this->generateUniqueSlug($validatedData['title'], $id);
        }

        // Update content
        $content = $this->storage->update($id, $validatedData);

        // Clear caches
        $this->clearContentCaches($id);

        return $content;
    }

    private function handleDelete(int $id): bool
    {
        // Verify content exists
        $content = $this->storage->find($id);
        if (!$content) {
            throw new ContentNotFoundException("Content with ID {$id} not found");
        }

        // Delete content
        $result = $this->storage->delete($id);

        // Clear caches
        $this->clearContentCaches($id);

        return $result;
    }

    private function handlePublish(int $id): bool
    {
        // Verify content exists and is draft
        $content = $this->storage->find($id);
        if (!$content) {
            throw new ContentNotFoundException("Content with ID {$id} not found");
        }

        if ($content->status === 'published') {
            throw new InvalidOperationException("Content is already published");
        }

        // Update status
        $result = $this->storage->update($id, ['status' => 'published']);

        // Clear caches
        $this->clearContentCaches($id);

        return $result;
    }

    private function generateUniqueSlug(string $title, ?int $excludeId = null): string
    {
        $baseSlug = \Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = $baseSlug . '-' . $counter++;
        }

        return $slug;
    }

    private function slugExists(string $slug, ?int $excludeId): bool
    {
        $query = DB::table('contents')->where('slug', $slug);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    private function clearContentCaches(?int $id = null): void
    {
        if ($id) {
            $this->cache->forget("content.{$id}");
        }
        $this->cache->tags(['content_list', 'content_feed'])->flush();
    }
}
