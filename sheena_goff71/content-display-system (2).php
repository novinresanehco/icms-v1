namespace App\Core\Display;

class ContentDisplayManager implements DisplayManagerInterface 
{
    protected TemplateEngine $templateEngine;
    protected SecurityManager $security;
    protected MediaManager $media;
    protected CacheManager $cache;

    protected array $securityRules = [
        'content' => ['strip_tags', 'escape'],
        'media' => ['validate_source', 'secure_url'],
        'meta' => ['sanitize', 'validate']
    ];

    public function display(Content $content, array $options = []): string 
    {
        DB::beginTransaction();
        try {
            // Security validation
            $this->validateDisplay($content);
            
            // Get or compile display template
            $template = $this->getTemplate($content->getType());
            
            // Prepare content with security
            $data = $this->prepareContent($content);
            
            // Process media elements
            $media = $this->processMedia($content->getMedia());
            
            // Merge all display data
            $displayData = array_merge($data, [
                'media' => $media,
                'options' => $this->validateOptions($options)
            ]);
            
            // Render with caching
            $result = $this->renderWithCache($template, $displayData);
            
            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new DisplayException(
                'Content display failed: ' . $e->getMessage(), 
                previous: $e
            );
        }
    }

    protected function validateDisplay(Content $content): void 
    {
        if (!$this->security->validateContent($content)) {
            throw new SecurityException('Content failed security validation');
        }
    }

    protected function prepareContent(Content $content): array 
    {
        $raw = $content->toArray();
        return array_map(function($value, $key) {
            $rules = $this->securityRules[$key] ?? ['escape'];
            return $this->applySecurityRules($value, $rules);
        }, $raw, array_keys($raw));
    }

    protected function processMedia(array $media): array 
    {
        return array_map(function($item) {
            return $this->media->processSecure($item);
        }, $media);
    }

    protected function renderWithCache(string $template, array $data): string 
    {
        $cacheKey = $this->generateCacheKey($template, $data);
        return $this->cache->remember($cacheKey, 3600, function() use ($template, $data) {
            return $this->templateEngine->render($template, $data);
        });
    }

    protected function getTemplate(string $type): string 
    {
        $template = $this->loadTemplate($type);
        if (!$template) {
            throw new TemplateException("Template not found for type: {$type}");
        }
        return $template;
    }

    protected function validateOptions(array $options): array 
    {
        return array_intersect_key(
            $options,
            array_flip(['layout', 'theme', 'format'])
        );
    }

    protected function applySecurityRules($value, array $rules): mixed 
    {
        foreach ($rules as $rule) {
            $value = $this->security->applyRule($rule, $value);
        }
        return $value;
    }

    protected function generateCacheKey(string $template, array $data): string 
    {
        return 'display.' . hash('sha256', serialize([$template, $data]));
    }

    protected function loadTemplate(string $type): ?string 
    {
        return $this->templateEngine->load("content.{$type}");
    }
}
