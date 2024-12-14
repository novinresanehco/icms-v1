namespace App\Core\Template;

class TemplateEngine implements TemplateInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private FileSystem $files;
    private CompilerInterface $compiler;
    private ValidatorService $validator;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        FileSystem $files,
        CompilerInterface $compiler,
        ValidatorService $validator,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->files = $files;
        $this->compiler = $compiler;
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    public function render(string $template, array $data = []): string
    {
        $startTime = microtime(true);

        try {
            return $this->security->executeCriticalOperation(
                new TemplateRenderOperation(
                    $template,
                    $this->validateData($data),
                    $this->compiler,
                    $this->cache
                ),
                SecurityContext::fromRequest()
            );
        } finally {
            $this->metrics->timing(
                'template.render.duration',
                microtime(true) - $startTime
            );
        }
    }

    public function compile(string $template): string
    {
        return $this->security->executeCriticalOperation(
            new TemplateCompileOperation(
                $template,
                $this->compiler,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function renderContent(Content $content): string
    {
        $cacheKey = $this->getCacheKey($content);
        
        return $this->cache->remember($cacheKey, 3600, function () use ($content) {
            return $this->security->executeCriticalOperation(
                new ContentRenderOperation(
                    $content,
                    $this->compiler,
                    $this->validator
                ),
                SecurityContext::fromRequest()
            );
        });
    }

    private function validateData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->sanitizeString($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->validateData($value);
            }
        }

        return $data;
    }

    private function sanitizeString(string $value): string
    {
        $sanitized = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        
        if ($sanitized !== $value) {
            $this->metrics->increment('template.xss_prevention');
        }

        return $sanitized;
    }

    private function getCacheKey(Content $content): string
    {
        return sprintf(
            'template:content:%d:%s',
            $content->getId(),
            $content->getUpdatedAt()->getTimestamp()
        );
    }

    public function extend(string $name, callable $extension): void
    {
        $this->security->executeCriticalOperation(
            new TemplateExtensionOperation(
                $name,
                $extension,
                $this->compiler
            ),
            SecurityContext::fromRequest()
        );
    }

    public function getCompiler(): CompilerInterface
    {
        return $this->compiler;
    }

    public function clearCache(): void
    {
        $this->cache->tags(['templates'])->flush();
    }

    private function validateTemplate(string $template): void
    {
        if (!$this->files->exists($template)) {
            throw new TemplateNotFoundException("Template not found: {$template}");
        }

        if (!$this->validator->validateTemplate($template)) {
            throw new TemplateValidationException("Invalid template: {$template}");
        }
    }

    private function validateExtension(string $name, callable $extension): void
    {
        if (!preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name)) {
            throw new InvalidExtensionException("Invalid extension name: {$name}");
        }

        if ($this->compiler->hasExtension($name)) {
            throw new ExtensionExistsException("Extension already exists: {$name}");
        }
    }

    private function compileTemplate(string $template): string
    {
        $compiled = $this->compiler->compile($template);
        
        if (empty($compiled)) {
            throw new CompilationException("Failed to compile template: {$template}");
        }

        return $compiled;
    }

    private function renderCompiled(string $compiled, array $data): string
    {
        extract($data);
        ob_start();

        try {
            eval('?>' . $compiled);
            return ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new RenderException(
                "Failed to render template: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}
