```php
namespace App\Core\Template;

final class TemplateEngine
{
    private SecurityValidator $validator;
    private ContentRenderer $renderer;
    private CacheManager $cache;
    private MonitoringService $monitor;
    
    private const CRITICAL_LIMITS = [
        'max_render_time' => 200,    // ms
        'max_template_size' => 5120, // KB
        'max_nested_level' => 3
    ];

    public function render(string $template, array $data): string
    {
        $operationId = $this->monitor->startOperation('template_render');
        
        try {
            $this->validateTemplate($template);
            $this->validateData($data);
            
            $result = $this->cache->remember("template.$template", function() use ($template, $data) {
                return $this->executeRender($template, $data);
            });
            
            $this->validateOutput($result);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->handleRenderFailure($e, $operationId);
            throw new RenderException('Template rendering failed', 0, $e);
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function executeRender(string $template, array $data): string
    {
        $compiled = $this->compileTemplate($template);
        return $this->renderer->render($compiled, $data);
    }

    private function compileTemplate(string $template): CompiledTemplate
    {
        return new CompiledTemplate(
            $this->validator->sanitize($template),
            $this->validator->extractDependencies($template)
        );
    }

    private function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplate($template, self::CRITICAL_LIMITS)) {
            throw new ValidationException('Template validation failed');
        }
    }
}

final class ContentRenderer
{
    private SecurityContext $context;
    private MediaRenderer $mediaRenderer;
    private array $allowedTags;

    public function renderContent(Content $content): string
    {
        return $this->context->executeSecure(function() use ($content) {
            $sanitized = $this->sanitizeContent($content);
            return $this->processContent($sanitized);
        });
    }

    public function renderMedia(array $media): string
    {
        return $this->context->executeSecure(function() use ($media) {
            return $this->mediaRenderer->renderGallery($media);
        });
    }

    private function sanitizeContent(Content $content): SanitizedContent
    {
        return new SanitizedContent(
            $content,
            $this->allowedTags
        );
    }
}

final class UIComponentRegistry
{
    private array $components = [];
    private SecurityValidator $validator;

    public function register(string $name, UIComponent $component): void
    {
        if (!$this->validator->validateComponent($component)) {
            throw new SecurityException('Component validation failed');
        }
        
        $this->components[$name] = $component;
    }

    public function render(string $name, array $props): string
    {
        if (!isset($this->components[$name])) {
            throw new ComponentNotFoundException($name);
        }

        return $this->components[$name]->render($props);
    }
}
```
