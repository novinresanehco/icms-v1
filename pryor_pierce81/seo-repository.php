<?php

namespace App\Core\Repository;

use App\Models\Seo;
use App\Core\Events\SeoEvents;
use App\Core\Exceptions\SeoRepositoryException;

class SeoRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Seo::class;
    }

    /**
     * Get SEO data for entity
     */
    public function getSeoData(string $entityType, int $entityId): ?Seo
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("entity.{$entityType}.{$entityId}"),
            $this->cacheTime,
            fn() => $this->model->where('entity_type', $entityType)
                               ->where('entity_id', $entityId)
                               ->first()
        );
    }

    /**
     * Update or create SEO data
     */
    public function updateOrCreateSeo(string $entityType, int $entityId, array $data): Seo
    {
        try {
            $seo = $this->model->updateOrCreate(
                [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId
                ],
                [
                    'title' => $data['title'] ?? null,
                    'description' => $data['description'] ?? null,
                    'keywords' => $data['keywords'] ?? null,
                    'canonical_url' => $data['canonical_url'] ?? null,
                    'robots' => $data['robots'] ?? 'index,follow',
                    'og_title' => $data['og_title'] ?? null,
                    'og_description' => $data['og_description'] ?? null,
                    'og_image' => $data['og_image'] ?? null
                ]
            );

            $this->clearCache();
            event(new SeoEvents\SeoDataUpdated($seo));

            return $seo;
        } catch (\Exception $e) {
            throw new SeoRepositoryException(
                "Failed to update SEO data: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get entities missing SEO data
     */
    public function getMissingSeoEntities(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('missing'),
            $this->cacheTime,
            fn() => DB::table('contents')
                     ->leftJoin('seo', function($join) {
                         $join->on('contents.id', '=', 'seo.entity_id')
                              ->where('seo.entity_type', '=', 'content');
                     })
                     ->whereNull('seo.id')
                     ->where('contents.status', 'published')
                     ->select('contents.*')
                     ->get()
        );
    }

    /**
     * Generate sitemap data
     */
    public function getSitemapData(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('sitemap'),
            $this->cacheTime,
            fn() => $this->model->with(['content' => function($query) {
                $query->where('status', 'published');
            }])->get()
        );
    }
}
