namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Services\ValidationService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

class TemplateEngine
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function render(string $template, array $data, array $context): string
    {
        return $this->security->executeSecureOperation(
            function() use ($template, $data, $context) {
                $cacheKey = $this->generateCacheKey($template, $data);
                
                if ($cached = $this->cache->get($cacheKey)) {
                    return $cached;
                }

                $validated = $this->validateTemplateData($data);
                $compiled = $this->compileTemplate($template, $validated);
                $rendered = $this->renderTemplate($compiled, $validated);

                if ($this->shouldCache($template)) {
                    $this->cache->put($cacheKey, $rendered, $this->getCacheDuration($template));
                }

                return $rendered;
            },
            $context
        );
    }

    public function compileTemplate(string $template, array $data): string
    {
        $this->validateTemplate($template);
        
        $compiled = Blade::compileString($template);
        
        return $this->processDirectives($compiled, $data);
    }

    public function registerComponent(string $name, string $view): void
    {
        $this->validateComponentRegistration($name, $view);
        
        Blade::component($name, $view);
    }

    public function extendBlade(string $name, callable $callback): void
    {
        $this->validateDirectiveExtension($name);
        
        Blade::directive($name, function($expression) use ($callback) {
            return $this->wrapDirective($callback, $expression);
        });
    }

    protected function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplate($template)) {
            throw new TemplateException('Invalid template structure');
        }

        if ($this->containsMaliciousCode($template)) {
            throw new SecurityException('Potential security threat detected in template');
        }
    }

    protected function validateTemplateData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->sanitizeContent($value);
            }
            if (is_array($value)) {
                return $this->validateTemplateData($value);
            }
            return $value;
        }, $data);
    }

    protected function processDirectives(string $template, array $data): string
    {
        $template = $this->processCustomDirectives($template, $data);
        $template = $this->processSecurityDirectives($template);
        return $this->processLayoutDirectives($template);
    }

    protected function renderTemplate(string $compiled, array $data): string
    {
        $view = View::make('template-string', [
            'template' => $compiled,
            'data' => $data
        ]);

        return $view->render();
    }

    protected function generateCacheKey(string $template, array $data): string
    {
        return 'template:' . hash('sha256', $template . serialize($data));
    }

    protected function shouldCache(string $template): bool
    {
        return strlen($template) > $this->config['cache_threshold'] &&
               !$this->containsDynamicContent($template);
    }

    protected function getCacheDuration(string $template): int
    {
        return $this->isDynamicTemplate($template) 
            ? $this->config['dynamic_cache_ttl'] 
            : $this->config['static_cache_ttl'];
    }

    protected function sanitizeContent(string $content): string
    {
        $content = strip_tags($content, $this->config['allowed_tags']);
        $content = $this->removeScriptContent($content);
        return htmlspecialchars($content, ENT_QUOTES | ENT_HTML5);
    }

    protected function containsMaliciousCode(string $template): bool
    {
        foreach ($this->config['malicious_patterns'] as $pattern) {
            if (preg_match($pattern, $template)) {
                return true;
            }
        }
        return false;
    }

    protected function containsDynamicContent(string $template): bool
    {
        return Str::contains($template, ['@php', '@eval', '{!!']) ||
               preg_match('/{{\s*\$[^}]+}}/', $template);
    }

    protected function isDynamicTemplate(string $template): bool
    {
        return Str::contains($template, ['@include', '@extends', '@component']);
    }

    protected function removeScriptContent(string $content): string
    {
        return preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
    }

    protected function validateComponentRegistration(string $name, string $view): void
    {
        if (!$this->validator->validateComponentName($name)) {
            throw new TemplateException('Invalid component name');
        }

        if (!View::exists($view)) {
            throw new TemplateException('Component view does not exist');
        }
    }

    protected function validateDirectiveExtension(string $name): void
    {
        if (!$this->validator->validateDirectiveName($name)) {
            throw new TemplateException('Invalid directive name');
        }

        if (Blade::getCustomDirectives()[$name] ?? false) {
            throw new TemplateException('Directive already exists');
        }
    }

    protected function wrapDirective(callable $callback, string $expression): string
    {
        try {
            $result = $callback($expression);
            return $this->validateDirectiveOutput($result);
        } catch (\Throwable $e) {
            throw new TemplateException('Directive execution failed: ' . $e->getMessage());
        }
    }

    protected function validateDirectiveOutput(string $output): string
    {
        if ($this->containsMaliciousCode($output)) {
            throw new SecurityException('Malicious code detected in directive output');
        }
        return $output;
    }

    protected function processCustomDirectives(string $template, array $data): string
    {
        foreach ($this->config['custom_directives'] as $directive => $handler) {
            $template = $this->processDirective($template, $directive, $handler, $data);
        }
        return $template;
    }

    protected function processSecurityDirectives(string $template): string
    {
        $template = $this->processCsrfDirectives($template);
        $template = $this->processXssProtection($template);
        return $this->processPermissionDirectives($template);
    }

    protected function processLayoutDirectives(string $template): string
    {
        $template = $this->processIncludes($template);
        $template = $this->processExtends($template);
        return $this->processSections($template);
    }
}
