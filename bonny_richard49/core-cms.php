<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Cache, Storage};

class ContentManager
{
    private SecurityManager $security;
    private array $config;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function createContent(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentCreation($data),
            ['context' => 'content_creation']
        );
    }

    public function updateContent(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentUpdate($id, $data),
            ['context' => 'content_update']
        );
    }

    public function deleteContent(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processContentDeletion($id),
            ['context' => 'content_deletion']
        );
    }

    protected function processContentCreation(array $data): Content
    {
        $content = new Content($data);
        $content->save();
        
        if (isset($data['media'])) {
            $this->processMedia($content, $data['media']);
        }
        
        $this->updateCache($content);
        return $content;
    }

    protected function processContentUpdate(int $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        $content->update($data);
        
        if (isset($data['media'])) {
            $this->processMedia($content, $data['media']);
        }
        
        $this->updateCache($content);
        return $content;
    }

    protected function processContentDeletion(int $id): bool
    {
        $content = Content::findOrFail($id);
        $this->clearCache($content);
        return $content->delete();
    }

    protected function processMedia(Content $content, array $media): void
    {
        foreach ($media as $file) {
            $path = Storage::put('content', $file);
            $content->media()->create(['path' => $path]);
        }
    }

    protected function updateCache(Content $content): void
    {
        $key = "content_{$content->id}";
        Cache::put($key, $content, $this->config['cache_duration']);
    }

    protected function clearCache(Content $content): void
    {
        $key = "content_{$content->id}";
        Cache::forget($key);
    }
}

class CategoryManager
{
    private SecurityManager $security;
    private array $config;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function createCategory(array $data): Category
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processCategoryCreation($data),
            ['context' => 'category_creation']
        );
    }

    public function updateCategory(int $id, array $data): Category
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processCategoryUpdate($id, $data),
            ['context' => 'category_update']
        );
    }

    protected function processCategoryCreation(array $data): Category
    {
        $category = new Category($data);
        $category->save();
        
        $this->updateCache($category);
        return $category;
    }

    protected function processCategoryUpdate(int $id, array $data): Category
    {
        $category = Category::findOrFail($id);
        $category->update($data);
        
        $this->updateCache($category);
        return $category;
    }

    protected function updateCache(Category $category): void
    {
        $key = "category_{$category->id}";
        Cache::put($key, $category, $this->config['cache_duration']);
    }
}

class MediaManager 
{
    private SecurityManager $security;
    private array $config;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function uploadMedia($file, array $data = []): Media
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processMediaUpload($file, $data),
            ['context' => 'media_upload']
        );
    }

    protected function processMediaUpload($file, array $data): Media
    {
        $path = Storage::put('media', $file);
        
        $media = new Media([
            'path' => $path,
            'type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => $data
        ]);
        
        $media->save();
        return $media;
    }

    public function deleteMedia(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processMediaDeletion($id),
            ['context' => 'media_deletion']
        );
    }

    protected function processMediaDeletion(int $id): bool
    {
        $media = Media::findOrFail($id);
        Storage::delete($media->path);
        return $media->delete();
    }
}
