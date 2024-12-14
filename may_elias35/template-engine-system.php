namespace App\Core\Template;

class TemplateEngine implements TemplateInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private TemplateRepository $repository;
    private CompilerService $compiler;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        TemplateRepository $repository,
        CompilerService $compiler,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->compiler = $compiler;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string 
    {
        return $this->security->executeCriticalOperation(new class($template, $data, $this->cache, $this->compiler) implements CriticalOperation {
            private string $template;
            private array $data;
            private CacheManager $cache;
            private CompilerService $compiler;

            public function __construct(string $template, array $data, CacheManager $cache, CompilerService $compiler)
            {
                $this->template = $template;
                $this->data = $data;
                $this->cache = $cache;
                $this->compiler = $compiler;
            }

            public function execute(): OperationResult 
            {
                $cacheKey = "template:{$this->template}:" . md5(serialize($this->data));
                
                return new OperationResult($this->cache->remember($cacheKey, function() {
                    $compiled = $this->compiler->compile($this->template);
                    return $this->compiler->render($compiled, $this->data);
                }));
            }

            public function getValidationRules(): array 
            {
                return [
                    'template' => 'required|string',
                    'data' => 'array'
                ];
            }

            public function getData(): array 
            {
                return [
                    'template' => $this->template,
                    'data_keys' => array_keys($this->data)
                ];
            }

            public function getRequiredPermissions(): array 
            {
                return ['template.render'];
            }

            public function getRateLimitKey(): string 
            {
                return "template:render:{$this->template}";
            }
        });
    }

    public function compile(string $template): CompiledTemplate 
    {
        return $this->security->executeCriticalOperation(new class($template, $this->compiler, $this->validator) implements CriticalOperation {
            private string $template;
            private CompilerService $compiler;
            private ValidationService $validator;

            public function __construct(string $template, CompilerService $compiler, ValidationService $validator)
            {
                $this->template = $template;
                $this->compiler = $compiler;
                $this->validator = $validator;
            }

            public function execute(): OperationResult 
            {
                $compiled = $this->compiler->compile($this->template);
                
                $this->validator->validateCompiledTemplate($compiled);
                
                return new OperationResult($compiled);
            }

            public function getValidationRules(): array 
            {
                return ['template' => 'required|string'];
            }

            public function getData(): array 
            {
                return ['template' => $this->template];
            }

            public function getRequiredPermissions(): array 
            {
                return ['template.compile'];
            }

            public function getRateLimitKey(): string 
            {
                return "template:compile:" . md5($this->template);
            }
        });
    }

    public function extend(string $name, callable $extension): void 
    {
        $this->security->executeCriticalOperation(new class($name, $extension, $this->compiler) implements CriticalOperation {
            private string $name;
            private callable $extension;
            private CompilerService $compiler;

            public function __construct(string $name, callable $extension, CompilerService $compiler)
            {
                $this->name = $name;
                $this->extension = $extension;
                $this->compiler = $compiler;
            }

            public function execute(): OperationResult 
            {
                $this->compiler->registerExtension($this->name, $this->extension);
                return new OperationResult(true);
            }

            public function getValidationRules(): array 
            {
                return ['name' => 'required|string|max:255'];
            }

            public function getData(): array 
            {
                return ['name' => $this->name];
            }

            public function getRequiredPermissions(): array 
            {
                return ['template.extend'];
            }

            public function getRateLimitKey(): string 
            {
                return "template:extend:{$this->name}";
            }
        });
    }

    public function clearCache(): void 
    {
        $this->security->executeCriticalOperation(new class($this->cache) implements CriticalOperation {
            private CacheManager $cache;

            public function __construct(CacheManager $cache)
            {
                $this->cache = $cache;
            }

            public function execute(): OperationResult 
            {
                $this->cache->tags(['templates'])->flush();
                return new OperationResult(true);
            }

            public function getValidationRules(): array 
            {
                return [];
            }

            public function getData(): array 
            {
                return [];
            }

            public function getRequiredPermissions(): array 
            {
                return ['template.cache.clear'];
            }

            public function getRateLimitKey(): string 
            {
                return 'template:cache:clear';
            }
        });
    }
}
