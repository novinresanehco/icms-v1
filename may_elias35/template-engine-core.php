namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Collection;

class TemplateEngine implements TemplateInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private TemplateRepository $repository;
    private TemplateCompiler $compiler;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        TemplateRepository $repository,
        TemplateCompiler $compiler,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->repository = $repository;
        $this->compiler = $compiler;
        $this->metrics = $metrics;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeSecureOperation(function() use ($template, $data) {
            $startTime = microtime(true);
            
            $cacheKey = $this->getCacheKey($template, $data);
            
            $content = $this->cache->remember($cacheKey, 3600, function() use ($template, $data) {
                $template = $this->repository->findByName($template);
                return $this->compiler->compile($template, $data);
            });
            
            $this->metrics->recordRenderTime(microtime(true) - $startTime);
            
            return $content;
        }, ['action' => 'template.render']);
    }

    public function renderContent(Content $content): string
    {
        return $this->security->executeSecureOperation(function() use ($content) {
            $template = $content->template ?? 'default';
            $data = $this->prepareContentData($content);
            
            return $this->render($template, $data);
        }, ['action' => 'template.renderContent', 'resource' => $content->id]);
    }

    public function renderMediaGallery(array $media): string
    {
        return $this->security->executeSecureOperation(function() use ($media) {
            $data = ['media' => Collection::make($media)];
            return $this->render('gallery', $data);
        }, ['action' => 'template.renderGallery']);
    }

    public function compile(string $template): string
    {
        return $this->security->executeSecureOperation(function() use ($template) {
            $compiled = $this->compiler->compileString($template);
            $this->validateCompiled($compiled);
            return $compiled;
        }, ['action' => 'template.compile']);
    }

    public function extend(string $name, callable $extension): void
    {
        $this->security->executeSecureOperation(function() use ($name, $extension) {
            $this->compiler->extend($name, $extension);
            $this->clearCache();
        }, ['action' => 'template.extend']);
    }

    private function getCacheKey(string $template, array $data): string
    {
        return sprintf(
            'template:%s:%s',
            $template,
            md5(serialize($data))
        );
    }

    private function prepareContentData(Content $content): array
    {
        return [
            'content' => $content,
            'meta' => $content->meta,
            'media' => $content->media,
            'author' => $content->author,
            'categories' => $content->categories,
            'tags' => $content->tags
        ];
    }

    private function validateCompiled(string $compiled): void
    {
        if (preg_match('/<\?php/i', $compiled)) {
            throw new SecurityException('PHP code detected in template');
        }

        if (preg_match('/\beval\b/i', $compiled)) {
            throw new SecurityException('Eval detected in template');
        }
    }

    private function clearCache(): void
    {
        $this->cache->tags(['templates'])->flush();
    }
}

class TemplateCompiler
{
    private array $extensions = [];
    private array $variables = [];
    private SecurityManager $security;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
    }

    public function compile(Template $template, array $data): string
    {
        $this->variables = $data;
        
        $content = $template->content;
        
        // Process extensions
        foreach ($this->extensions as $name => $extension) {
            $content = $extension($content, $data);
        }
        
        // Process variables
        $content = $this->processVariables($content);
        
        // Process includes
        $content = $this->processIncludes($content);
        
        // Process conditions
        $content = $this->processConditions($content);
        
        // Process loops
        $content = $this->processLoops($content);
        
        return $content;
    }

    public function compileString(string $template): string
    {
        return $this->compile(new Template(['content' => $template]), []);
    }

    public function extend(string $name, callable $extension): void
    {
        $this->extensions[$name] = $extension;
    }

    private function processVariables(string $content): string
    {
        return preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', function($matches) {
            $variable = trim($matches[1]);
            return $this->getVariableValue($variable);
        }, $content);
    }

    private function processIncludes(string $content): string
    {
        return preg_replace_callback('/@include\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', function($matches) {
            $template = trim($matches[1]);
            return $this->security->executeSecureOperation(function() use ($template) {
                return $this->compile(
                    $this->repository->findByName($template),
                    $this->variables
                );
            }, ['action' => 'template.include']);
        }, $content);
    }

    private function processConditions(string $content): string
    {
        return preg_replace_callback('/@if\s*\((.*?)\)(.*?)(@else(.*?))?@endif/s', function($matches) {
            $condition = trim($matches[1]);
            $ifContent = $matches[2];
            $elseContent = $matches[4] ?? '';
            
            return $this->evaluateCondition($condition)
                ? $this->compile($ifContent, $this->variables)
                : $this->compile($elseContent, $this->variables);
        }, $content);
    }

    private function processLoops(string $content): string
    {
        return preg_replace_callback('/@foreach\s*\((.*?)\s+as\s+(.*?)\)(.*?)@endforeach/s', function($matches) {
            $array = $this->getVariableValue(trim($matches[1]));
            $itemVar = trim($matches[2]);
            $loopContent = $matches[3];
            
            $result = '';
            foreach ($array as $item) {
                $this->variables[$itemVar] = $item;
                $result .= $this->compile($loopContent, $this->variables);
            }
            
            return $result;
        }, $content);
    }

    private function getVariableValue(string $path): string
    {
        $segments = explode('.', $path);
        $value = $this->variables;
        
        foreach ($segments as $segment) {
            if (!isset($value[$segment])) {
                return '';
            }
            $value = $value[$segment];
        }
        
        return htmlspecialchars((string) $value, ENT_QUOTES);
    }

    private function evaluateCondition(string $condition): bool
    {
        $condition = preg_replace_callback('/\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/', function($matches) {
            $var = $matches[1];
            return isset($this->variables[$var]) 
                ? var_export($this->variables[$var], true)
                : 'null';
        }, $condition);
        
        return $this->security->executeSecureOperation(function() use ($condition) {
            return eval("return $condition;");
        }, ['action' => 'template.evaluate']);
    }
}
