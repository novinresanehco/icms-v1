<?php

namespace App\Core\Sitemap\Services;

use App\Core\Sitemap\Models\Sitemap;
use App\Core\Sitemap\Repositories\SitemapRepository;
use Illuminate\Support\Facades\{Storage, URL};

class SitemapService
{
    public function __construct(
        private SitemapRepository $repository,
        private SitemapGenerator $generator,
        private SitemapValidator $validator
    ) {}

    public function generate(): Sitemap
    {
        return DB::transaction(function () {
            $sitemap = $this->repository->create([
                'status' => 'pending'
            ]);

            $this->generator->generate($sitemap);
            return $sitemap;
        });
    }

    public function regenerate(): Sitemap
    {
        $this->repository->deleteAll();
        return $this->generate();
    }

    public function addUrl(string $url, array $options = []): void
    {
        $this->validator->validateUrl($url, $options);
        $this->repository->addUrl($url, $options);
    }

    public function removeUrl(string $url): void
    {
        $this->repository->removeUrl($url);
    }

    public function getUrls(): Collection
    {
        return $this->repository->getUrls();
    }

    public function getSitemapPath(): string
    {
        $sitemap = $this->repository->getLatest();
        
        if (!$sitemap || !$sitemap->isCompleted()) {
            throw new SitemapException('No completed sitemap available');
        }

        return $sitemap->file_path;
    }

    public function getSitemapUrl(): string
    {
        return URL::to($this->getSitemapPath());
    }

    public function notify(array $searchEngines = []): array
    {
        $results = [];
        $sitemapUrl = $this->getSitemapUrl();
        
        foreach ($searchEngines as $engine => $pingUrl) {
            try {
                $response = Http::get($pingUrl . urlencode($sitemapUrl));
                $results[$engine] = $response->successful();
            } catch (\Exception $e) {
                $results[$engine] = false;
            }
        }

        return $results;
    }

    public function getStats(): array
    {
        return $this->repository->getStats();
    }

    public function cleanup(int $keepLast = 5): int
    {
        $oldSitemaps = $this->repository->getOldSitemaps($keepLast);
        $count = 0;

        foreach ($oldSitemaps as $sitemap) {
            if ($sitemap->file_path && Storage::exists($sitemap->file_path)) {
                Storage::delete($sitemap->file_path);
            }
            $sitemap->delete();
            $count++;
        }

        return $count;
    }
}
