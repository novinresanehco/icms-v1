<?php

namespace App\Core\Repositories;

use App\Models\SEO;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class SEORepository extends AdvancedRepository
{
    protected $model = SEO::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getForUrl(string $url): ?SEO
    {
        return $this->executeQuery(function() use ($url) {
            return $this->cache->remember("seo.url.{$url}", function() use ($url) {
                return $this->model
                    ->where('url', $url)
                    ->first();
            });
        });
    }

    public function updateOrCreate(string $url, array $data): SEO
    {
        return $this->executeTransaction(function() use ($url, $data) {
            $seo = $this->model->updateOrCreate(
                ['url' => $url],
                [
                    'title' => $data['title'] ?? null,
                    'description' => $data['description'] ?? null,
                    'keywords' => $data['keywords'] ?? null,
                    'robots' => $data['robots'] ?? null,
                    'canonical' => $data['canonical'] ?? null,
                    'og_title' => $data['og_title'] ?? null,
                    'og_description' => $data['og_description'] ?? null,
                    'og_image' => $data['og_image'] ?? null
                ]
            );
            
            $this->cache->forget("seo.url.{$url}");
            return $seo;
        });
    }

    public function getCustomPages(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('seo.custom_pages', function() {
                return $this->model
                    ->whereNotNull('custom_page')
                    ->orderBy('url')
                    ->get();
            });
        });
    }

    public function deleteForUrl(string $url): void
    {
        $this->executeTransaction(function() use ($url) {
            $this->model->where('url', $url)->delete();
            $this->cache->forget("seo.url.{$url}");
        });
    }
}
