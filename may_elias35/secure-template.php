```php
namespace App\Core\Template;

final class SecureTemplateEngine
{
    private SecurityValidator $validator;
    private TemplateRenderer $renderer;
    private TemplateCache $cache;
    private MediaHandler $media;

    private const SECURITY_LIMITS = [
        'max_template_size' => 5120,
        'max_render_time' => 200,
        'max_includes' => 5,
        'max_media_size' => 10240
    ];

    public function render(string $template, array $data): string 
    {
        $operationId = uniqid('render_', true);
        
        try {
            // Pre-render validation
            $this->validator->validateTemplate($template, self::SECURITY_LIMITS);
            $this->validator->validateData($data);

            // Execute rendering
            $rendered = $this->cache->remember("template.$template", function() use ($template, $data) {
                return $this->executeRender($template, $data);
            });

            // Post-render validation
            $this->validator->validateOutput($rendered);
            
            return $rendered;

        } catch (\Throwable $e) {
            $this->handleRenderFailure($operationId, $e);
            throw $e;
        }
    }

    private function executeRender(string $template, array $data): string 
    {
        $compiled = $this->compile($template);
        return $this->renderer->render($compiled, $data);
    }

    private function compile(string $template): CompiledTemplate 
    {
        return new CompiledTemplate(
            $this->validator->sanitize($template),
            $this->validator->extractDependencies($template)
        );
    }
}

final class MediaGalleryRenderer 
{
    private MediaProcessor $processor;
    private SecurityContext $security;
    private CacheManager $cache;

    public function renderGallery(array $media, array $options = []): string 
    {
        $this->security->validateMediaAccess($media);
        
        return $this->cache->remember("gallery." . $this->getGalleryKey($media), function() use ($media, $options) {
            return $this->buildGallery($media, $options);
        });
    }

    private function buildGallery(array $media, array $options): string 
    {
        $processed = array_map(
            fn($item) => $this->processor->process($item, $options),
            $media
        );

        return $this->generateGalleryHtml($processed);
    }

    private function generateGalleryHtml(array $media): string 
    {
        return view('components.gallery', [
            'media' => $media,
            'security_token' => $this->security->generateToken()
        ])->render();
    }
}

final class UIComponentManager 
{
    private ComponentRegistry $registry;
    private SecurityValidator $validator;
    private RenderContext $context;

    public function renderComponent(string $name, array $props): string 
    {
        if (!$this->registry->has($name)) {
            throw new ComponentNotFoundException($name);
        }

        $this->validator->validateProps($props);
        
        return $this->registry->get($name)->render(
            $props,
            $this->context
        );
    }

    public function registerComponent(string $name, UIComponent $component): void 
    {
        $this->validator->validateComponent($component);
        $this->registry->register($name, $component);
    }
}

trait SecureRendering 
{
    private function validateRenderContext(RenderContext $context): void 
    {
        if (!$context->hasValidToken()) {
            throw new SecurityException('Invalid render context');
        }

        if (!$context->isWithinLimits(self::SECURITY_LIMITS)) {
            throw new SecurityException('Security limits exceeded');
        }
    }

    private function sanitizeOutput(string $output): string 
    {
        return htmlspecialchars(
            $this->xssFilter->clean($output),
            ENT_QUOTES | ENT_HTML5
        );
    }
}
```
