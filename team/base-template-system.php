namespace App\Core\Template;

class TemplateManager
{
    private TemplateRepository $repository;
    private SecurityManager $security;
    private ViewEngine $engine;
    private CacheManager $cache;

    public function __construct(
        TemplateRepository $repository,
        SecurityManager $security,
        ViewEngine $engine,
        CacheManager $cache
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->engine = $engine;
        $this->cache = $cache;
    }

    public function render(string $template, array $data = []): string
    {
        $template = $this->loadTemplate($template);
        $data = $this->prepareData($data);

        return $this->cache->remember(
            $this->getCacheKey($template, $data),
            fn() => $this->engine->render($template, $data)
        );
    }

    private function loadTemplate(string $name): Template
    {
        return $this->repository->findOrFail($name);
    }

    public function compileTemplate(string $template): CompiledTemplate
    {
        return $this->engine->compile($template);
    }

    public function updateTemplate(string $name, array $data): Template
    {
        DB::beginTransaction();
        try {
            $template = $this->repository->update($name, $data);
            $this->cache->invalidateTemplateCache($name);
            DB::commit();
            return $template;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function prepareData(array $data): array
    {
        return array_merge($data, [
            'csrf' => csrf_token(),
            'user' => auth()->user(),
            'config' => config('cms.templates')
        ]);
    }

    private function getCacheKey(Template $template, array $data): string
    {
        return sprintf(
            'template.%s.%s',
            $template->getName(),
            md5(serialize($data))
        );
    }
}

class ViewEngine
{
    private SecurityManager $security;
    private array $extensions = [];

    public function render(Template $template, array $data): string
    {
        $compiled = $this->compile($template);
        return $this->evaluate($compiled, $data);
    }

    public function compile(Template $template): CompiledTemplate
    {
        $content = $template->getContent();
        
        foreach ($this->extensions as $extension) {
            $content = $extension->process($content);
        }

        return new CompiledTemplate($content);
    }

    private function evaluate(CompiledTemplate $template, array $data): string
    {
        extract($this->security->sanitizeData($data));
        ob_start();
        eval('?>' . $template->getContent());
        return ob_get_clean();
    }

    public function extend(string $name, callable $extension): void
    {
        $this->extensions[$name] = $extension;
    }
}

class TemplateRepository
{
    use CacheableRepository;

    protected Model $model;

    public function findOrFail(string $name): Template
    {
        return $this->remember(
            "template.{$name}",
            fn() => $this->model->whereName($name)->firstOrFail()
        );
    }

    public function update(string $name, array $data): Template
    {
        $template = $this->findOrFail($name);
        $template->update($data);
        return $template;
    }
}

class TemplateCacheManager
{
    private CacheInterface $cache;
    private int $duration;
    private array $tags = ['templates'];

    public function remember(string $key, Closure $callback)
    {
        return $this->cache->tags($this->tags)->remember(
            $key,
            $this->duration,
            $callback
        );
    }

    public function invalidateTemplateCache(string $name): void
    {
        $this->cache->tags($this->tags)->flush();
    }
}

class CompiledTemplate
{
    private string $content;
    private array $metadata;

    public function __construct(string $content, array $metadata = [])
    {
        $this->content = $content;
        $this->metadata = $metadata;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class Template
{
    private string $name;
    private string $content;
    private array $metadata;
    private Carbon $updatedAt;

    public function getName(): string
    {
        return $this->name;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function update(array $data): bool
    {
        $this->content = $data['content'];
        $this->metadata = $data['metadata'] ?? [];
        $this->updatedAt = Carbon::now();
        return true;
    }
}

interface CacheInterface
{
    public function remember(string $key, int $duration, Closure $callback);
    public function tags(array $tags);
    public function flush();
}
