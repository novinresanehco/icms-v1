```php
namespace App\Core\Templates;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private array $config;

    private const MAX_TEMPLATE_SIZE = 1048576; // 1MB
    private const CACHE_TTL = 3600;
    private const MAX_INCLUDES = 10;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): TemplateResponse
    {
        return $this->security->executeSecureOperation(function() use ($template, $data) {
            // Validate template and data
            $this->validateTemplate($template);
            $this->validateData($data);
            
            // Get cached version if available
            $cacheKey = $this->generateCacheKey($template, $data);
            
            return $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($template, $data) {
                // Load template
                $content = $this->loadTemplate($template);
                
                // Compile template
                $compiled = $this->compileTemplate($content);
                
                // Process includes
                $processed = $this->processIncludes($compiled);
                
                // Render template
                $rendered = $this->renderContent($processed, $data);
                
                // Apply security filters
                $secured = $this->applySecurityFilters($rendered);
                
                // Optimize output
                $optimized = $this->optimizeOutput($secured);
                
                $this->metrics->recordTemplateRender($template);
                
                return new TemplateResponse($optimized);
            });
        }, ['operation' => 'template_render']);
    }

    public function compile(string $template): TemplateResponse
    {
        return $this->security->executeSecureOperation(function() use ($template) {
            $this->validateTemplate($template);
            
            try {
                // Load template
                $content = $this->loadTemplate($template);
                
                // Parse template
                $ast = $this->parseTemplate($content);
                
                // Validate syntax
                $this->validateSyntax($ast);
                
                // Compile to PHP
                $compiled = $this->compileToPhp($ast);
                
                // Optimize code
                $optimized = $this->optimizeCode($compiled);
                
                // Store compiled version
                $this->storeCompiled($template, $optimized);
                
                $this->metrics->recordTemplateCompilation($template);
                
                return new TemplateResponse($optimized);
                
            } catch (\Exception $e) {
                $this->handleCompilationFailure($template, $e);
                throw $e;
            }
        }, ['operation' => 'template_compile']);
    }

    private function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplateName($template)) {
            throw new ValidationException('Invalid template name');
        }

        if (!file_exists($this->getTemplatePath($template))) {
            throw new TemplateException('Template not found');
        }
    }

    private function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->validator->validateVariable($key, $value)) {
                throw new ValidationException("Invalid template data: {$key}");
            }
        }
    }

    private function loadTemplate(string $template): string
    {
        $path = $this->getTemplatePath($template);
        $content = file_get_contents($path);

        if (strlen($content) > self::MAX_TEMPLATE_SIZE) {
            throw new TemplateException('Template size exceeds limit');
        }

        return $content;
    }

    private function parseTemplate(string $content): array
    {
        $parser = new TemplateParser($this->config['syntax_rules']);
        return $parser->parse($content);
    }

    private function validateSyntax(array $ast): void
    {
        $validator = new SyntaxValidator($this->config['syntax_constraints']);
        
        if (!$validator->validate($ast)) {
            throw new SyntaxException('Template syntax validation failed');
        }
    }

    private function compileToPhp(array $ast): string
    {
        $compiler = new PhpCompiler([
            'escape_html' => true,
            'strict_variables' => true,
            'safe_functions' => $this->config['allowed_functions']
        ]);

        return $compiler->compile($ast);
    }

    private function processIncludes(string $content): string
    {
        $includeCount = 0;
        
        return preg_replace_callback('/@include\(\'([^\']+)\'\)/', function($matches) use (&$includeCount) {
            if (++$includeCount > self::MAX_INCLUDES) {
                throw new TemplateException('Maximum include depth exceeded');
            }
            
            return $this->loadTemplate($matches[1]);
        }, $content);
    }

    private function applySecurityFilters(string $content): string
    {
        // Apply XSS protection
        $content = $this->security->escapeHtml($content);
        
        // Apply CSP nonce
        $content = $this->security->applyCspNonce($content);
        
        // Remove potentially dangerous patterns
        $content = $this->security->sanitizeOutput($content);
        
        return $content;
    }

    private function optimizeOutput(string $content): string
    {
        if ($this->config['minify_html']) {
            $content = $this->minifyHtml($content);
        }
        
        if ($this->config['compress_output']) {
            $content = $this->compressOutput($content);
        }
        
        return $content;
    }

    private function getTemplatePath(string $template): string
    {
        return $this->config['template_path'] . '/' . $template;
    }

    private function generateCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }

    private function storeCompiled(string $template, string $compiled): void
    {
        $path = $this->config['compiled_path'] . '/' . md5($template) . '.php';
        file_put_contents($path, $compiled);
    }

    private function handleCompilationFailure(string $template, \Exception $e): void
    {
        $this->metrics->recordCompilationFailure($template);
        
        Log::error('Template compilation failed', [
            'template' => $template,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function minifyHtml(string $content): string
    {
        return preg_replace([
            '/\>[^\S ]+/s',
            '/[^\S ]+\</s',
            '/(\s)+/s'
        ], [
            '>',
            '<',
            '\\1'
        ], $content);
    }

    private function compressOutput(string $content): string
    {
        return gzcompress($content, 9);
    }
}
```

This implementation provides:

1. Secure Template Processing:
- Template validation
- Content sanitization
- XSS protection
- CSP integration

2. Performance Features:
- Template caching
- Output optimization
- HTML minification
- Efficient compilation

3. Security Controls:
- Syntax validation
- Include depth limits
- Function whitelisting
- Output sanitization

4. Monitoring:
- Performance metrics
- Error tracking
- Compilation status
- Resource usage

The system ensures secure template rendering while maintaining optimal performance and strict security controls.