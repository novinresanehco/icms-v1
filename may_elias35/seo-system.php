// File: app/Core/SEO/Manager/SeoManager.php
<?php

namespace App\Core\SEO\Manager;

class SeoManager
{
    protected MetaGenerator $metaGenerator;
    protected UrlOptimizer $urlOptimizer;
    protected SitemapGenerator $sitemapGenerator;
    protected AnalyticsCollector $analytics;

    public function optimize(Content $content): SeoResult
    {
        $result = new SeoResult();
        
        // Generate meta tags
        $result->setMetaTags(
            $this->metaGenerator->generate($content)
        );

        // Optimize URL
        $result->setOptimizedUrl(
            $this->urlOptimizer->optimize($content)
        );

        // Generate structured data
        $result->setStructuredData(
            $this->generateStructuredData($content)
        );

        return $result;
    }

    public function generateSitemap(): void
    {
        $this->sitemapGenerator->generate();
    }

    public function getAnalytics(): array
    {
        return $this->analytics->collect();
    }
}

// File: app/Core/SEO/Meta/MetaGenerator.php
<?php

namespace App\Core\SEO\Meta;

class MetaGenerator
{
    protected TitleGenerator $titleGenerator;
    protected DescriptionGenerator $descriptionGenerator;
    protected KeywordGenerator $keywordGenerator;
    protected SchemaGenerator $schemaGenerator;

    public function generate(Content $content): array
    {
        return [
            'title' => $this->titleGenerator->generate($content),
            'description' => $this->descriptionGenerator->generate($content),
            'keywords' => $this->keywordGenerator->generate($content),
            'schema' => $this->schemaGenerator->generate($content),
            'og' => $this->generateOpenGraph($content),
            'twitter' => $this->generateTwitterCards($content)
        ];
    }

    protected function generateOpenGraph(Content $content): array
    {
        return [
            'og:title' => $content->getTitle(),
            'og:description' => $content->getDescription(),
            'og:type' => $content->getType(),
            'og:url' => $content->getUrl(),
            'og:image' => $content->getFeaturedImage()
        ];
    }

    protected function generateTwitterCards(Content $content): array
    {
        return [
            'twitter:card' => 'summary_large_image',
            'twitter:title' => $content->getTitle(),
            'twitter:description' => $content->getDescription(),
            'twitter:image' => $content->getFeaturedImage()
        ];
    }
}

// File: app/Core/SEO/Sitemap/SitemapGenerator.php
<?php

namespace App\Core\SEO\Sitemap;

class SitemapGenerator
{
    protected ContentRepository $contentRepository;
    protected UrlGenerator $urlGenerator;
    protected FileWriter $fileWriter;
    protected SitemapConfig $config;

    public function generate(): void
    {
        $sitemap = new Sitemap();
        
        // Add pages
        foreach ($this->contentRepository->getPublishedContent() as $content) {
            $sitemap->add(
                $this->createUrlEntry($content)
            );
        }
        
        // Generate sitemap file
        $this->fileWriter->write(
            $sitemap->toString(),
            $this->config->getPath()
        );
    }

    protected function createUrlEntry(Content $content): UrlEntry
    {
        return new UrlEntry([
            'loc' => $this->urlGenerator->generate($content),
            'lastmod' => $content->getUpdatedAt()->toAtomString(),
            'changefreq' => $this->getChangeFrequency($content),
            'priority' => $this->getPriority($content)
        ]);
    }

    protected function getChangeFrequency(Content $content): string
    {
        return match ($content->getType()) {
            'page' => 'monthly',
            'post' => 'weekly',
            'product' => 'daily',
            default => 'monthly'
        };
    }
}

// File: app/Core/SEO/Analytics/AnalyticsCollector.php
<?php

namespace App\Core\SEO\Analytics;

class AnalyticsCollector
{
    protected MetricsCollector $metrics;
    protected RankingTracker $rankingTracker;
    protected PerformanceAnalyzer $performanceAnalyzer;

    public function collect(): array
    {
        return [
            'metrics' => $this->collectMetrics(),
            'rankings' => $this->collectRankings(),
            'performance' => $this->collectPerformance()
        ];
    }

    protected function collectMetrics(): array
    {
        return $this->metrics->collect([
            'pageviews',
            'visitors',
            'bounce_rate',
            'average_time_on_page'
        ]);
    }

    protected function collectRankings(): array
    {
        return $this->rankingTracker->track([
            'keywords' => $this->getTargetKeywords(),
            'timeframe' => 'last_30_days'
        ]);
    }

    protected function collectPerformance(): array
    {
        return $this->performanceAnalyzer->analyze([
            'page_speed',
            'mobile_friendliness',
            'core_web_vitals'
        ]);
    }
}
