namespace App\Core\Template;

class TemplateEngine implements TemplateEngineInterface 
{
    private TemplateLoader $loader;
    private TemplateCompiler $compiler;
    private TemplateCacheManager $cache;
    private TemplateValidator $validator;
    private array $globalData = [];

    public function __construct(
        TemplateLoader $loader,
        TemplateCompiler $compiler,
        TemplateCacheManager $cache,
        TemplateValidator $validator
    ) {
        $this->loader = $loader;
        $this->compiler = $compiler;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function render(string $template, array $data = []): string 
    {
        return DB::transaction(function() use ($template, $data) {
            $source = $this->loader->load($template);
            $this->validator->validateSource($source);
            
            $cacheKey = $this->getCacheKey($template, $data);
            
            return $this->cache->remember($cacheKey, function() use ($source, $data) {
                $compiled = $this->compiler->compile($source);
                $this->validator->validateCompiled($compiled);
                
                $mergedData = array_merge($this->globalData, $data);
                return $this->renderCompiled($compiled, $mergedData);
            });
        });
    }

    protected function renderCompiled(CompiledTemplate $compiled, array $data): string 
    {
        $this->validator->validateData($data);
        return $compiled->render($data);
    }

    protected function getCacheKey(string $template, array $data): string 
    {
        return sprintf(
            'template:%s:%s',
            md5($template),
            md5(serialize($data))
        );
    }

    public function addGlobal(string $key, $value): void 
    {
        $this->globalData[$key] = $value;
    }
}

class TemplateCacheManager 
{
    private CacheInterface $cache;
    private int $ttl;

    public function __construct(CacheInterface $cache, int $ttl = 3600) 
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    public function remember(string $key, callable $callback): string 
    {
        if ($cached = $this->cache->get($key)) {
            return $cached;
        }

        $result = $callback();
        $this->cache->put($key, $result, $this->ttl);
        return $result;
    }
}

class TemplateValidator 
{
    public function validateSource(TemplateSource $source): void 
    {
        if (!$source->isValid()) {
            throw new TemplateValidationException('Invalid template source');
        }
    }

    public function validateCompiled(CompiledTemplate $compiled): void 
    {
        if (!$compiled->isValid()) {
            throw new TemplateValidationException('Invalid compiled template');
        }
    }

    public function validateData(array $data): void 
    {
        foreach ($data as $key => $value) {
            if (!$this->isValidValue($value)) {
                throw new TemplateValidationException("Invalid data value for key: {$key}");
            }
        }
    }

    private function isValidValue($value): bool 
    {
        return !is_resource($value) && !is_object($value) || $value instanceof Stringable;
    }
}

interface TemplateEngineInterface 
{
    public function render(string $template, array $data = []): string;
    public function addGlobal(string $key, $value): void;
}
