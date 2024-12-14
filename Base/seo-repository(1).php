<?php

namespace App\Repositories;

use App\Models\SEO;
use App\Repositories\Contracts\SEORepositoryInterface;
use Illuminate\Support\Collection;

class SEORepository extends BaseRepository implements SEORepositoryInterface
{
    protected array $searchableFields = ['title', 'description', 'keywords'];
    protected array $filterableFields = ['seoable_type'];
    protected array $relationships = ['seoable'];

    public function createOrUpdateForModel($model, array $data): SEO
    {
        try {
            DB::beginTransaction();
            
            $seo = $this->model->updateOrCreate(
                [
                    'seoable_type' => get_class($model),
                    'seoable_id' => $model->id
                ],
                [
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'keywords' => $data['keywords'] ?? null,
                    'robots' => $data['robots'] ?? 'index,follow',
                    'canonical_url' => $data['canonical_url'] ?? null,
                    'og_title' => $data['og_title'] ?? $data['title'],
                    'og_description' => $data['og_description'] ?? ($data['description'] ?? null),
                    'og_image' => $data['og_image'] ?? null,
                    'schema_markup' => $data['schema_markup'] ?? null
                ]
            );
            
            DB::commit();
            $this->clearModelCache();
            return $seo;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to update SEO: {$e->getMessage()}");
        }
    }

    public function getSEOForUrl(string $url): ?SEO
    {
        return Cache::remember(
            $this->getCacheKey("url.{$url}"),
            $this->cacheTTL,
            fn() => $this->model->where('url', $url)->first()
        );
    }

    public function generateSitemap(): Collection
    {
        return $this->model
            ->where('robots', 'not like', '%noindex%')
            ->orderBy('updated_at', 'desc')
            ->get(['url', 'updated_at', 'priority', 'change_frequency']);
    }

    public function updateMetaTags(int $id, array $metaTags): SEO
    {
        $seo = $this->findOrFail($id);
        $seo->meta_tags = array_merge($seo->meta_tags ?? [], $metaTags);
        $seo->save();
        
        $this->clearModelCache();
        return $seo;
    }
}
