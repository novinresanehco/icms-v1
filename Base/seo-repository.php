<?php

namespace App\Repositories;

use App\Models\Seo;
use App\Repositories\Contracts\SeoRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SeoRepository extends BaseRepository implements SeoRepositoryInterface
{
    protected array $searchableFields = ['title', 'description', 'keywords', 'path'];
    protected array $filterableFields = ['type', 'status', 'entity_type'];

    public function getForPath(string $path): ?Seo
    {
        return Cache::tags(['seo'])->remember("seo.path.{$path}", 3600, function() use ($path) {
            return $this->model
                ->where('path', $path)
                ->first();
        });
    }

    public function updateOrCreate(string $path, array $data): Seo
    {
        $seo = $this->model->updateOrCreate(
            ['path' => $path],
            [
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'keywords' => $data['keywords'] ?? null,
                'robots' => $data['robots'] ?? 'index,follow',
                'canonical' => $data['canonical'] ?? null,
                'og_title' => $data['og_title'] ?? null,
                'og_description' => $data['og_description'] ?? null,
                'og_image' => $data['og_image'] ?? null,
                'schema_markup' => $data['schema_markup'] ?? null,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null
            ]
        );

        Cache::tags(['seo'])->forget("seo.path.{$path}");
        return $seo;
    }

    public function getAllSitemapData(): Collection
    {
        return Cache::tags(['seo'])->remember('seo.sitemap', 3600, function() {
            return $this->model
                ->where('robots', 'not like', '%noindex%')
                ->select('path', 'updated_at', 'priority', 'change_frequency')
                ->get();
        });
    }

    public function bulkUpdate(array $data): int
    {
        $updated = 0;
        
        foreach ($data as $path => $seoData) {
            try {
                $this->updateOrCreate($path, $seoData);
                $updated++;
            } catch (\Exception $e) {
                \Log::error("Error updating SEO for path {$path}: " . $e->getMessage());
            }
        }

        Cache::tags(['seo'])->flush();
        return $updated;
    }
}
