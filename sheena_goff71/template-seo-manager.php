<?php

namespace App\Core\Template\SEO;

use App\Core\Template\Exceptions\SEOException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SEOManager
{
    private Collection $meta;
    private Collection $schemas;
    private array $config;
    private ?string $title = null;
    private ?string $description = null;
    private array $robots = [];

    public function __construct(array $config = [])
    {
        $this->meta = new Collection();
        $this->schemas = new Collection();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Set page title
     *
     * @param string $title
     * @param bool $appendSiteName
     * @return self
     */
    public function setTitle(string $title, bool $appendSiteName = true): self
    {
        $this->title = $appendSiteName 
            ? sprintf("%s %s %s", $title, $this->config['title_separator'], $this->config['site_name'])
            : $title;
        
        return $this;
    }

    /**
     * Set meta description
     *
     * @param string $description
     * @return self
     */
    public function setDescription(string $description): self
    {
        $this->description = Str::limit($description, $this->config['max_description_length']);
        return $this;
    }

    /**
     * Add meta tag
     *
     * @param string $name
     * @param string $content
     * @param string $type
     * @return self
     */
    public function addMeta(string $name, string $content, string $type = 'name'): self
    {
        $this->meta->put($name, [
            'type' => $type,
            'content' => $content
        ]);
        
        return $this;
    }

    /**
     * Add Open Graph meta tag
     *
     * @param string $property
     * @param string $content
     * @return self
     */
    public function addOpenGraph(string $property, string $content): self
    {
        return $this->addMeta($property, $content, 'property');
    }

    /**
     * Add Twitter Card meta tag
     *
     * @param string $name
     * @param string $content
     * @return self
     */
    public function addTwitterCard(string $name, string $content): self
    {
        return $this->addMeta("twitter:{$name}", $content);
    }

    /**
     * Add structured data schema
     *
     * @param array $schema
     * @return self
     */
    public function addSchema(array $schema): self
    {
        $this->validateSchema($schema);
        $this->schemas->push($schema);
        return $this;
    }

    /**
     * Set robots meta tag
     *
     * @param array $directives
     * @return self
     */
    public function setRobots(array $directives): self
    {
        $this->robots = array_merge($this->robots, $directives);
        return $this;
    }

    /**
     * Generate canonical URL
     *
     * @param string|null $url
     * @return self
     */
    public function setCanonical(?string $url = null): self
    {
        $canonical = $url ?? request()->url();
        return $this->addMeta('canonical', $canonical, 'link');
    }

    /**
     * Generate meta tags HTML
     *
     * @return string
     */
    public function generate(): string
    {
        $html = [];

        // Title tag
        if ($this->title) {
            $html[] = "<title>{$this->title}</title>";
        }

        // Description
        if ($this->description) {
            $html[] = $this->generateMetaTag('description', $this->description);
        }

        // Robots
        if (!empty($this->robots)) {
            $html[] = $this->generateMetaTag('robots', implode(',', $this->robots));
        }

        // Other meta tags
        foreach ($this->meta as $name => $data) {
            $html[] = $this->generateMetaTag($name, $data['content'], $data['type']);
        }

        // Structured data
        if ($this->schemas->isNotEmpty()) {
            $html[] = $this->generateStructuredData();
        }

        return implode("\n", $html);
    }

    /**
     * Generate meta tag
     *
     * @param string $name
     * @param string $content
     * @param string $type
     * @return string
     */
    protected function generateMetaTag(string $name, string $content, string $type = 'name'): string
    {
        if ($type === 'link') {
            return "<link rel=\"{$name}\" href=\"{$content}\">";
        }
        
        return "<meta {$type}=\"{$name}\" content=\"{$content}\">";
    }

    /**
     * Generate structured data script
     *
     * @return string
     */
    protected function generateStructuredData(): string
    {
        return sprintf(
            '<script type="application/ld+json">%s</script>',
            json_encode($this->schemas->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    /**
     * Validate schema structure
     *
     * @param array $schema
     * @throws SEOException
     * @return void
     */
    protected function validateSchema(array $schema): void
    {
        if (!isset($schema['@context'], $schema['@type'])) {
            throw new SEOException('Invalid schema structure: missing required properties');
        }
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'site_name' => config('app.name'),
            'title_separator' => '-',
            'max_description_length' => 160,
            'default_robots' => ['index', 'follow'],
            'twitter_site' => '@yoursite',
            'fb_app_id' => '',
        ];
    }
}

class SchemaGenerator
{
    /**
     * Generate Organization schema
     *
     * @param array $data
     * @return array
     */
    public function organization(array $data): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $data['name'],
            'url' => $data['url'],
            'logo' => $data['logo'] ?? null,
            'contactPoint' => $data['contact'] ?? null,
            'sameAs' => $data['social_profiles'] ?? []
        ];
    }

    /**
     * Generate Article schema
     *
     * @param array $data
     * @return array
     */
    public function article(array $data): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $data['title'],
            'description' => $data['description'] ?? null,
            'image' => $data['image'] ?? null,
            'datePublished' => $data['published_at'],
            'dateModified' => $data['updated_at'] ?? $data['published_at'],
            'author' => [
                '@type' => 'Person',
                'name' => $data['author_name']
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => $data['publisher_name'],
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $data['publisher_logo']
                ]
            ]
        ];
    }

    /**
     * Generate BreadcrumbList schema
     *
     * @param array $items
     * @return array
     */
    public function breadcrumbs(array $items): array
    {
        $itemsList = [];
        
        foreach ($items as $position => $item) {
            $itemsList[] = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'item' => [
                    '@id' => $item['url'],
                    'name' => $item['title']
                ]
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $itemsList
        ];
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\SEO\SEOManager;
use App\Core\Template\SEO\SchemaGenerator;

class SEOServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(SEOManager::class, function ($app) {
            return new SEOManager([
                'site_name' => config('app.name'),
                'twitter_site' => config('seo.twitter_site'),
                'fb_app_id' => config('seo.fb_app_id')
            ]);
        });

        $this->app->singleton(SchemaGenerator::class);
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $seo = $this->app->make(SEOManager::class);

        // Set default meta tags
        $seo->addMeta('viewport', 'width=device-width, initial-scale=1')
            ->setRobots(['index', 'follow'])
            ->addOpenGraph('site_name', config('app.name'));

        // Add default schema
        $schema = $this->app->make(SchemaGenerator::class);
        $seo->addSchema($schema->organization([
            'name' => config('app.name'),
            'url' => config('app.url'),
            'logo' => config('seo.logo_url')
        ]));
    }
}
