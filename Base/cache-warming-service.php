<?php

namespace App\Core\Services\Cache;

use Illuminate\Support\Facades\Cache;
use App\Core\Contracts\{
    ContentRepositoryInterface,
    CategoryRepositoryInterface,
    TagRepositoryInterface
};
use Illuminate\Support\Facades\Log;

class RepositoryCacheWarmer
{
    protected ContentRepositoryInterface $contentRepository;
    protected CategoryRepositoryInterface $categoryRepository;
    protected TagRepositoryInterface $tagRepository;
    protected array $config;

    public function __construct(
        ContentRepositoryInterface $contentRepository,
        CategoryRepositoryInterface $categoryRepository,
        TagRepositoryInterface $tagRepository
    ) {
        $this->contentRepository = $contentRepository;
        $this->categoryRepository = $categoryRepository;
        $this->tagRepository = $tagRepository;
        $this->config = config('repository.cache.warming');
    }

    public function warmCache(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        try {
            $this->warmEntityCaches();
            $this->warmRelationshipCaches();
            $this->warmAggregationCaches();
            
            Log::info('Repository cache warming completed successfully');
        } catch (\Exception $e) {
            Log::error('Repository cache warming failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function warmEntityCaches(): void
    {
        // Warm category caches
        if (in_array('category', $this->config['entities'])) {
            $this->categoryRepository->getTree();
            $this->categoryRepository->getWithContentCount();
        }

        // Warm content caches
        if (in_array('content', $this->config['entities'])) {
            $this->contentRepository->getPopular();
            $this->contentRepository->published();
        }

        // Warm tag caches
        if (in_array('tag', $this->config['entities'])) {
            $this->tagRepository->getPopular();
        }
    }

    protected function warmRelationshipCaches(): void
    {
        // Warm category-content relationships
        $categories = $this->categoryRepository->all();
        foreach ($categories as $category) {
            $this->contentRepository->findByCategory($category->id, 10);
        }

        // Warm popular tags with content
        $tags = $this->tagRepository->getPopular(10);
        foreach ($tags as $tag) {
            $this->contentRepository->findByTag($tag->name, 10);
        }
    }

    protected function warmAggregationCaches(): void
    {
        // Warm aggregated data caches
        Cache::tags(['aggregation'])->remember('content_statistics', 3600, function() {
            return [
                'total_published' => $this->contentRepository->published()->count(),
                'total_draft' => $this->contentRepository->findWhere(['status' => 'draft'])->count(),
                'recent_content' => $this->contentRepository->findWhere([
                    ['created_at', '>=', now()->subDays(7)]
                ])->count()
            ];
        });

        Cache::tags(['aggregation'])->remember('category_statistics', 3600, function() {
            return [
                'total_categories' => $this->categoryRepository->all()->count(),
                'categories_with_content' => $this->categoryRepository->getWithContentCount()
                    ->filter(fn($category) => $category->contents_count > 0)
                    ->count()
            ];
        });
    }

    public function clearWarmCaches(): void
    {
        $tags = ['content', 'category', 'tag', 'aggregation'];
        foreach ($tags as $tag) {
            Cache::tags([$tag])->flush();
        }
    }
}
