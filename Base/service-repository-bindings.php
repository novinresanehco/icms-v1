<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    protected $repositories = [
        \App\Core\Repositories\Contracts\ContentRepositoryInterface::class => \App\Core\Repositories\ContentRepository::class,
        \App\Core\Repositories\Contracts\CategoryRepositoryInterface::class => \App\Core\Repositories\CategoryRepository::class,
        \App\Core\Repositories\Contracts\MediaRepositoryInterface::class => \App\Core\Repositories\MediaRepository::class,
        \App\Core\Repositories\Contracts\TagRepositoryInterface::class => \App\Core\Repositories\TagRepository::class,
    ];

    public function register(): void
    {
        foreach ($this->repositories as $interface => $implementation) {
            $this->app->bind($interface, $implementation);
        }
    }
}

namespace App\Core\Services;

/**
 * Content Service
 * Handles business logic for content management
 */
class ContentService
{
    public function __construct(
        protected ContentRepositoryInterface $contentRepository,
        protected CategoryRepositoryInterface $categoryRepository,
        protected MediaRepositoryInterface $mediaRepository,
        protected CacheManager $cache,
        protected EventDispatcher $events
    ) {}

    public function createContent(array $data): Content
    {
        $this->validateContentData($data);
        
        DB::beginTransaction();
        try {
            // Process and store media if present
            if (isset($data['media'])) {
                $data['media'] = $this->processMediaFiles($data['media']);
            }

            // Create content
            $content = $this->contentRepository->create($data);

            // Handle tags
            if (isset($data['tags'])) {
                $this->processTags($content, $data['tags']);
            }

            // Generate content meta
            $content->meta = $this->generateContentMeta($content);
            $content->save();

            DB::commit();
            
            // Clear relevant caches
            $this->cache->tags(['content', "category_{$content->category_id}"])->flush();
            
            // Dispatch events
            $this->events->dispatch(new ContentCreated($content));
            
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentCreationException($e->getMessage(), 0, $e);
        }
    }

    protected function validateContentData(array $data): void
    {
        $validator = Validator::make($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category_id' => 'required|exists:categories,id',
            'status' => 'required|in:draft,published',
            'media.*' => 'nullable|file|max:10240|mimes:jpeg,png,pdf',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function processMediaFiles(array $files): array
    {
        return collect($files)->map(function ($file) {
            return $this->mediaRepository->store($file, [
                'disk' => 'public',
                'directory' => 'content',
                'optimize' => true
            ]);
        })->pluck('id')->toArray();
    }

    protected function processTags(Content $content, array $tags): void
    {
        $tagIds = collect($tags)->map(function ($tagName) {
            return $this->tagRepository->firstOrCreate([
                'name' => $tagName,
                'slug' => Str::slug($tagName)
            ])->id;
        });

        $content->tags()->sync($tagIds);
    }

    protected function generateContentMeta(Content $content): array
    {
        return [
            'word_count' => str_word_count(strip_tags($content->content)),
            'reading_time' => ceil(str_word_count(strip_tags($content->content)) / 200),
            'has_media' => $content->media()->exists(),
            'last_edited' => now()->toISOString(),
        ];
    }
}

/**
 * Category Service
 * Handles business logic for category management
 */
class CategoryService
{
    public function __construct(
        protected CategoryRepositoryInterface $categoryRepository,
        protected CacheManager $cache,
        protected EventDispatcher $events
    ) {}

    public function createCategory(array $data): Category
    {
        $this->validateCategoryData($data);

        DB::beginTransaction();
        try {
            // Ensure proper ordering
            if (isset($data['parent_id'])) {
                $data['order'] = $this->getNextOrderNumber($data['parent_id']);
            }

            $category = $this->categoryRepository->create($data);

            DB::commit();

            // Clear category cache
            $this->cache->tags(['categories'])->flush();

            // Dispatch event
            $this->events->dispatch(new CategoryCreated($category));

            return $category;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CategoryCreationException($e->getMessage(), 0, $e);
        }
    }

    protected function validateCategoryData(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function getNextOrderNumber(?int $parentId): int
    {
        return $this->categoryRepository
            ->findWhere(['parent_id' => $parentId])
            ->max('order') + 1;
    }
}
