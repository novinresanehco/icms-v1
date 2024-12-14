<?php

namespace App\Core\Sitemap\Services;

use App\Core\Sitemap\Models\Sitemap;
use Illuminate\Support\Facades\Storage;
use XMLWriter;

class SitemapGenerator
{
    private XMLWriter $xml;
    private const MAX_URLS_PER_FILE = 50000;

    public function generate(Sitemap $sitemap): void
    {
        try {
            $sitemap->markAsProcessing();
            
            $urls = $this->collectUrls();
            $fileCount = ceil(count($urls) / self::MAX_URLS_PER_FILE);
            
            if ($fileCount > 1) {
                $this->generateSitemapIndex($sitemap, $urls, $fileCount);
            } else {
                $this->generateSingleSitemap($sitemap, $urls);
            }
            
            $sitemap->markAsCompleted();
        } catch (\Exception $e) {
            $sitemap->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    protected function collectUrls(): array
    {
        $urls = [];
        
        // Add static pages
        $urls = array_merge($urls, $this->getStaticPages());
        
        // Add dynamic content URLs
        $urls = array_merge($urls, $this->getDynamicUrls());
        
        return $urls;
    }

    protected function generateSitemapIndex(Sitemap $sitemap, array $urls, int $fileCount): void
    {
        $this->xml = new XMLWriter();
        $this->xml->openMemory();
        $this->xml->setIndent(true);
        
        $this->xml->startDocument('1.0', 'UTF-8');
        $this->xml->startElement('sitemapindex');
        $this->xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        
        for ($i = 1; $i <= $fileCount; $i++) {
            $filename = "sitemap_{$i}.xml";
            $urlsChunk = array_slice($urls, ($i - 1) * self::MAX_URLS_PER_FILE, self::MAX_URLS_PER_FILE);
            $this->generateSitemapFile($filename, $urlsChunk);
            
            $this->xml->startElement('sitemap');
            $this->xml->writeElement('loc', url($filename));
            $this->xml->writeElement('lastmod', date('c'));
            $this->xml->endElement();
        }
        
        $this->xml->endElement();
        
        $indexPath = "sitemaps/sitemap_index.xml";
        Storage::put($indexPath, $this->xml->outputMemory());
        
        $sitemap->update(['file_path' => $indexPath]);
    }

    protected function generateSingleSitemap(Sitemap $sitemap, array $urls): void
    {
        $this->xml = new XMLWriter();
        $this->xml->openMemory();
        $this->xml->setIndent(true);
        
        $this->xml->startDocument('1.0', 'UTF-8');
        $this->xml->startElement('urlset');
        $this->xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        
        foreach ($urls as $url) {
            $this->addUrl($url);
        }
        
        $this->xml->endElement();
        
        $path = "sitemaps/sitemap.xml";
        Storage::put($path, $this->xml->outputMemory());
        
        $sitemap->update(['file_path' => $path]);
    }

    protected function generateSitemapFile(string $filename, array $urls): void
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        
        foreach ($urls as $url) {
            $this->addUrl($url, $xml);
        }
        
        $xml->endElement();
        
        Storage::put("sitemaps/{$filename}", $xml->outputMemory());
    }

    protected function addUrl(array $url, XMLWriter $xml = null): void
    {
        $xml = $xml ?? $this->xml;
        
        $xml->startElement('url');
        $xml->writeElement('loc', $url['loc']);
        
        if (!empty($url['lastmod'])) {
            $xml->writeElement('lastmod', date('c', strtotime($url['lastmod'])));
        }
        
        if (!empty($url['changefreq'])) {
            $xml->writeElement('changefreq', $url['changefreq']);
        }
        
        if (!empty($url['priority'])) {
            $xml->writeElement('priority', number_format($url['priority'], 1));
        }
        
        $xml->endElement();
    }

    protected function getStaticPages(): array
    {
        return collect(config('sitemap.static_pages', []))
            ->map(function ($page) {
                return [
                    'loc' => url($page['url']),
                    'changefreq' => $page['changefreq'] ?? 'weekly',
                    'priority' => $page['priority'] ?? 0.8
                ];
            })
            ->toArray();
    }

    protected function getDynamicUrls(): array
    {
        $urls = [];
        
        // Collect URLs from registered providers
        foreach (config('sitemap.providers', []) as $provider) {
            if (class_exists($provider)) {
                $instance = app($provider);
                $urls = array_merge($urls, $instance->getUrls());
            }
        }
        
        return $urls;
    }
}
