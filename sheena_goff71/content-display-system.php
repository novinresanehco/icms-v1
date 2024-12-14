namespace App\Core\UI;

class ContentDisplayManager implements DisplayManagerInterface 
{
    private TemplateManager $templateManager;
    private SecurityManager $security;
    private CacheManager $cache;
    private MediaManager $media;

    public function __construct(
        TemplateManager $templateManager,
        SecurityManager $security,
        CacheManager $cache,
        MediaManager $media
    ) {
        $this->templateManager = $templateManager;
        $this->security = $security;
        $this->cache = $cache;
        $this->media = $media;
    }

    public function renderContent(Content $content, string $displayType = 'default'): string 
    {
        $cacheKey = $this->generateCacheKey($content, $displayType);

        return $this->cache->remember($cacheKey, function() use ($content, $displayType) {
            // Validate content security
            $this->security->validateContent($content);
            
            // Process content and media
            $processedContent = $this->processContent($content);
            $mediaContent = $this->processMediaContent($content);
            
            // Render with appropriate template
            return $this->templateManager->render(
                "content/$displayType",
                [
                    'content' => $processedContent,
                    'media' => $mediaContent,
                    'metadata' => $content->getMetadata()
                ]
            );
        });
    }

    private function processContent(Content $content): array 
    {
        return [
            'title' => $this->security->sanitize($content->getTitle()),
            'body' => $this->security->sanitizeHtml($content->getBody()),
            'summary' => $this->security->sanitize($content->getSummary()),
            'attributes' => $this->processAttributes($content->getAttributes())
        ];
    }

    private function processMediaContent(Content $content): array 
    {
        $media = $content->getMedia();
        
        return array_map(function($item) {
            return $this->media->process($item, [
                'secure' => true,
                'optimize' => true,
                'validate' => true
            ]);
        }, $media);
    }

    private function processAttributes(array $attributes): array 
    {
        return array_map(function($attr) {
            return $this->security->validateAttribute($attr);
        }, $attributes);
    }

    private function generateCacheKey(Content $content, string $displayType): string 
    {
        return hash('sha256', sprintf(
            'content.%s.%s.%s',
            $content->getId(),
            $displayType,
            $content->getUpdatedAt()->getTimestamp()
        ));
    }
}

interface DisplayManagerInterface 
{
    public function renderContent(Content $content, string $displayType = 'default'): string;
}

class ContentRenderer 
{
    private DisplayManagerInterface $displayManager;
    private array $registeredTypes = [];

    public function registerType(string $type, array $config): void 
    {
        if (!$this->validateTypeConfig($config)) {
            throw new DisplayTypeException("Invalid display type configuration");
        }
        $this->registeredTypes[$type] = $config;
    }

    public function render(Content $content, string $type = 'default'): string 
    {
        if (!isset($this->registeredTypes[$type])) {
            throw new DisplayTypeException("Unregistered display type: $type");
        }

        return $this->displayManager->renderContent($content, $type);
    }

    private function validateTypeConfig(array $config): bool 
    {
        return isset($config['template']) && 
               isset($config['security']) && 
               isset($config['cache']);
    }
}
