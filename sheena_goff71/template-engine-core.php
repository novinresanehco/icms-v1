namespace App\Core\Template\Engine;

class TemplateCompiler implements CompilerInterface
{
    private SecurityManager $security;
    private array $directives = [];
    private string $cachePath;

    public function compile(string $template): string
    {
        try {
            // Security scan before compilation
            $this->security->scanTemplate($template);

            $compiled = $template;
            
            // Process critical directives
            $compiled = $this->compileDirectives($compiled);
            $compiled = $this->compileEscapedEchos($compiled);
            $compiled = $this->compileRawEchos($compiled);
            $compiled = $this->compilePhpBlocks($compiled);
            $compiled = $this->compileFinalEscaping($compiled);

            // Final security validation
            return $this->security->validateCompiledTemplate($compiled);
            
        } catch (\Exception $e) {
            throw new TemplateCompilationException(
                'Template compilation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function compileDirectives(string $template): string
    {
        foreach ($this->directives as $pattern => $callback) {
            $template = preg_replace_callback($pattern, $callback, $template);
        }
        return $template;
    }

    private function compileEscapedEchos(string $template): string
    {
        return preg_replace(
            '/\{\{(.+?)\}\}/',
            '<?php echo $this->security->escape($1); ?>',
            $template
        );
    }

    private function compileRawEchos(string $template): string
    {
        return preg_replace(
            '/\{!!(.+?)!!\}/',
            '<?php echo $this->security->sanitizeHtml($1); ?>',
            $template
        );
    }

    private function compilePhpBlocks(string $template): string
    {
        return preg_replace(
            '/@php(.+?)@endphp/s',
            '<?php$1?>',
            $template
        );
    }

    private function compileFinalEscaping(string $template): string
    {
        return addslashes($template);
    }
}

class TemplateLoader implements LoaderInterface
{
    private CacheManager $cache;
    private string $basePath;
    private array $loadedTemplates = [];

    public function load(string $name): string
    {
        $path = $this->resolvePath($name);
        
        if (!isset($this->loadedTemplates[$path])) {
            if (!file_exists($path)) {
                throw new TemplateNotFoundException($name);
            }

            $content = file_get_contents($path);
            $this->loadedTemplates[$path] = $this->cache->remember(
                "template.load.$name",
                fn() => $this->validateTemplate($content)
            );
        }

        return $this->loadedTemplates[$path];
    }

    private function resolvePath(string $name): string
    {
        $path = $this->basePath . '/' . str_replace('.', '/', $name) . '.blade.php';
        return realpath($path) ?: $path;
    }

    private function validateTemplate(string $content): string
    {
        if (!$this->security->validateTemplateSource($content)) {
            throw new TemplateSurityException('Template failed security validation');
        }
        return $content;
    }
}

class TemplateCache implements CacheInterface
{
    private CacheManager $cache;
    private string $prefix = 'template.compiled.';

    public function get(string $key): ?string
    {
        return $this->cache->get($this->prefix . $key);
    }

    public function put(string $key, string $value, int $ttl = 3600): void
    {
        $this->cache->put($this->prefix . $key, $value, $ttl);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->prefix . $key);
    }

    public function flush(): void
    {
        $this->cache->tags(['templates'])->flush();
    }
}

interface CompilerInterface
{
    public function compile(string $template): string;
}

interface LoaderInterface
{
    public function load(string $name): string;
}

interface CacheInterface
{
    public function get(string $key): ?string;
    public function put(string $key, string $value, int $ttl = 3600): void;
    public function forget(string $key): void;
    public function flush(): void;
}
