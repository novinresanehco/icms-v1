<?php

namespace App\Core\Template;

use App\Core\Security\SecurityContext;
use App\Core\Cache\CacheManager;
use App\Core\Security\ValidationManager;
use Illuminate\Support\Facades\View;

class TemplateManager implements TemplateInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationManager $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationManager $validator,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function render(string $template, array $data = [], SecurityContext $context = null): string
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation($template, $data),
            $context ?? SecurityContext::system(),
            function() use ($template, $data) {
                $cacheKey = $this->getTemplateCacheKey($template, $data);

                return $this->cache->remember($cacheKey, 3600, function() use ($template, $data) {
                    // Validate template data
                    $validatedData = $this->validateTemplateData($data);
                    
                    // Compile template
                    $compiled = $this->compile($template);
                    
                    // Render with security context
                    $rendered = $this->renderSecure($compiled, $validatedData);
                    
                    // Post-process output
                    return $this->postProcessOutput($rendered);
                });
            }
        );
    }

    public function compile(string $template): string
    {
        $cacheKey = "template.compiled.{$template}";
        
        return $this->cache->remember($cacheKey, null, function() use ($template) {
            $source = $this->loadTemplate($template);
            return $this->compileTemplate($source);
        });
    }

    public function extend(string $name, callable $handler): void
    {
        View::composer($name, function($view) use ($handler) {
            $data = $handler($view->getData());
            $view->with($this->validateTemplateData($data));
        });
    }

    protected function loadTemplate(string $name): string
    {
        $path = $this->resolveTemplatePath($name);
        
        if (!file_exists($path)) {
            throw new TemplateNotFoundException("Template not found: {$name}");
        }

        return file_get_contents($path);
    }

    protected function compileTemplate(string $source): string
    {
        // Remove potentially dangerous PHP tags
        $source = preg_replace('/<\?(=|php)?/', '', $source);
        
        // Compile template directives
        $compiled = $this->compileDirectives($source);
        
        // Compile expressions
        $compiled = $this->compileExpressions($compiled);
        
        return $compiled;
    }

    protected function renderSecure(string $compiled, array $data): string
    {
        // Create isolated scope
        extract($data, EXTR_SKIP);
        
        // Capture output
        ob_start();
        try {
            eval('?>' . $compiled);
            return ob_get_clean();
        } catch (\Exception $e) {
            ob_end_clean();
            throw new TemplateRenderException($e->getMessage(), 0, $e);
        }
    }

    protected function validateTemplateData(array $data): array
    {
        $rules = [
            '*' => 'required',
            '*.string' => 'string|max:10000',
            '*.number' => 'numeric',
            '*.array' => 'array|max:1000',
            '*.html' => ['string', new SafeHtmlRule]
        ];

        return $this->validator->validate($data, $rules);
    }

    protected function postProcessOutput(string $output): string
    {
        // Remove comments
        $output = preg_replace('/<!--.*?-->/s', '', $output);
        
        // Minify HTML if enabled
        if ($this->config['minify'] ?? false) {
            $output = $this->minifyHtml($output);
        }
        
        // Add security headers
        $output = $this->addSecurityHeaders($output);
        
        return $output;
    }

    protected function compileDirectives(string $source): string
    {
        $directives = [
            // Control structures
            '/\@if\((.*?)\)/' => '<?php if($1): ?>',
            '/\@else/' => '<?php else: ?>',
            '/\@endif/' => '<?php endif; ?>',
            '/\@foreach\((.*?)\)/' => '<?php foreach($1): ?>',
            '/\@endforeach/' => '<?php endforeach; ?>',
            
            // Escaping
            '/\{\{(.*?)\}\}/' => '<?php echo $this->escape($1); ?>',
            '/\{\!\!(.*?)\!\}/' => '<?php echo $this->escapeHtml($1); ?>',
            
            // Include
            '/\@include\((.*?)\)/' => '<?php echo $this->render($1); ?>'
        ];

        return preg_replace(
            array_keys($directives),
            array_values($directives),
            $source
        );
    }

    protected function compileExpressions(string $source): string
    {
        return preg_replace_callback(
            '/\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/',
            function($matches) {
                return '$this->escape($' . $matches[1] . ')';
            },
            $source
        );
    }

    protected function minifyHtml(string $html): string
    {
        // Remove whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        
        // Remove whitespace around tags
        $html = preg_replace('/>\s+</', '><', $html);
        
        return trim($html);
    }

    protected function addSecurityHeaders(string $output): string
    {
        $csp = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';";
        
        return "<!--\nContent-Security-Policy: {$csp}\n-->\n" . $output;
    }

    protected function getTemplateCacheKey(string $template, array $data): string
    {
        return sprintf(
            'template.rendered.%s.%s',
            md5($template),
            md5(serialize($data))
        );
    }

    protected function resolveTemplatePath(string $name): string
    {
        $basePath = $this->config['templates_path'] ?? resource_path('views');
        return $basePath . '/' . str_replace('.', '/', $name) . '.blade.php';
    }

    protected function escape($value)
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
        }
        return $value;
    }

    protected function escapeHtml($value)
    {
        if (is_string($value)) {
            return $this->validator->validateHtml($value);
        }
        return $value;
    }
}
