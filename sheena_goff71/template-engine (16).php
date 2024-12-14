<?php

namespace App\Core\Template;

class TemplateEngine
{
    private SecurityValidator $validator;
    private CacheManager $cache;
    private ContentRenderer $contentRenderer;
    private array $registeredTemplates = [];

    public function __construct(
        SecurityValidator $validator,
        CacheManager $cache,
        ContentRenderer $contentRenderer
    ) {
        $this->validator = $validator;
        $this->cache = $cache;
        $this->contentRenderer = $contentRenderer;
    }

    public function registerTemplate(string $name, array $config): void 
    {
        $this->validator->validateTemplateConfig($name, $config);
        $this->registeredTemplates[$name] = $config;
    }

    public function render(string $template, array $data, array $options = []): string 
    {
        $this->validator->validateTemplate($template);
        $this->validator->validateData($data);
        
        $cacheKey = $this->generateCacheKey($template, $data);
        
        return $this->cache->remember($cacheKey, fn() => 
            $this->contentRenderer->render(
                $this->loadTemplate($template),
                $this->sanitizeData($data),
                $this->validateOptions($options)
            )
        );
    }

    private function loadTemplate(string $name): Template 
    {
        if (!isset($this->registeredTemplates[$name])) {
            throw new TemplateNotFoundException($name);
        }

        return new Template(
            $name,
            $this->registeredTemplates[$name],
            $this->validator
        );
    }

    private function sanitizeData(array $data): array 
    {
        return array_map(
            fn($value) => $this->validator->sanitize($value),
            $data
        );
    }

    private function validateOptions(array $options): array 
    {
        return $this->validator->validateOptions($options, [
            'cache' => 'boolean',
            'secure' => 'boolean',
            'version' => 'string'
        ]);
    }

    private function generateCacheKey(string $template, array $data): string 
    {
        return sprintf(
            'template:%s:%s',
            $template,
            md5(serialize($data))
        );
    }
}

class ContentRenderer
{
    private SecurityValidator $validator;
    private MediaProcessor $mediaProcessor;

    public function __construct(
        SecurityValidator $validator,
        MediaProcessor $mediaProcessor
    ) {
        $this->validator = $validator;
        $this->mediaProcessor = $mediaProcessor;
    }

    public function render(Template $template, array $data, array $options): string 
    {
        $this->validator->validateRenderOperation($template, $data);

        $content = $template->process($data);
        
        if ($template->hasMedia()) {
            $content = $this->mediaProcessor->process($content);
        }

        return $this->applySecurityHeaders($content);
    }

    private function applySecurityHeaders(string $content): string 
    {
        return $this->validator->applySecurityHeaders($content);
    }
}

class Template
{
    private string $name;
    private array $config;
    private SecurityValidator $validator;
    private array $sections = [];

    public function __construct(
        string $name,
        array $config,
        SecurityValidator $validator
    ) {
        $this->name = $name;
        $this->config = $config;
        $this->validator = $validator;
    }

    public function process(array $data): string 
    {
        $this->validator->validateTemplateData($this->name, $data);
        
        foreach ($this->config['sections'] as $section => $rules) {
            $this->sections[$section] = $this->processSection(
                $section,
                $data[$section] ?? null,
                $rules
            );
        }

        return $this->compile();
    }

    public function hasMedia(): bool 
    {
        return !empty($this->config['media']);
    }

    private function processSection(string $section, $content, array $rules): string 
    {
        return $this->validator->processSection($section, $content, $rules);
    }

    private function compile(): string 
    {
        return view($this->config['view'], [
            'sections' => $this->sections,
            'config' => $this->config
        ])->render();
    }
}

class MediaProcessor
{
    private array $processors = [];
    private SecurityValidator $validator;

    public function __construct(SecurityValidator $validator) 
    {
        $this->validator = $validator;
    }

    public function process(string $content): string 
    {
        foreach ($this->processors as $type => $processor) {
            $content = $processor->process(
                $content,
                $this->validator
            );
        }

        return $content;
    }

    public function registerProcessor(string $type, MediaProcessorInterface $processor): void 
    {
        $this->validator->validateMediaProcessor($type, $processor);
        $this->processors[$type] = $processor;
    }
}
