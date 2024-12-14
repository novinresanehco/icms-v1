namespace App\Core\Template;

class TemplateManager implements TemplateManagerInterface 
{
    private TemplateRepository $repository;
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        TemplateRepository $repository,
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function render(string $template, array $data = []): string 
    {
        return $this->executeSecurely(function() use ($template, $data) {
            // Validate and sanitize template data
            $validatedData = $this->validator->validateTemplateData($data);
            
            // Get template with caching
            $compiledTemplate = $this->cache->remember(
                "template.$template",
                fn() => $this->compileTemplate($template)
            );

            // Render with security controls
            return $this->renderSecurely($compiledTemplate, $validatedData);
        });
    }

    protected function compileTemplate(string $template): CompiledTemplate
    {
        $source = $this->repository->getTemplate($template);
        
        // Security scan template source
        $this->security->scanTemplate($source);
        
        return $this->compiler->compile($source);
    }

    protected function renderSecurely(CompiledTemplate $template, array $data): string
    {
        try {
            // Create isolated rendering environment
            $renderer = $this->createSecureRenderer();
            
            // Execute rendering with monitoring
            $result = $renderer->render($template, $data);
            
            // Validate output before returning
            $this->validator->validateOutput($result);
            
            return $result;
            
        } catch (TemplateException $e) {
            $this->handleRenderingFailure($e);
            throw $e;
        }
    }

    protected function executeSecurely(callable $operation): mixed
    {
        return DB::transaction(function() use ($operation) {
            try {
                // Execute with full monitoring
                $result = $operation();
                
                // Validate result integrity
                $this->validator->validateResult($result);
                
                return $result;
                
            } catch (\Exception $e) {
                $this->handleOperationFailure($e);
                throw $e;
            }
        });
    }

    protected function createSecureRenderer(): SecureTemplateRenderer
    {
        return new SecureTemplateRenderer(
            $this->security,
            $this->validator,
            config('template.render')
        );
    }

    protected function handleRenderingFailure(TemplateException $e): void
    {
        // Log failure with full context
        Log::error('Template rendering failed', [
            'exception' => $e,
            'template' => $e->getTemplate(),
            'context' => $e->getContext()
        ]);
        
        // Execute recovery procedures
        $this->executeFailureRecovery($e);
    }
}

// Secure template renderer with strict isolation
class SecureTemplateRenderer
{
    private SecurityManager $security;
    private ValidationService $validator;
    private array $config;

    public function render(CompiledTemplate $template, array $data): string
    {
        // Create isolated environment
        $sandbox = $this->createSandbox();
        
        // Execute template with full monitoring
        return $sandbox->execute(function() use ($template, $data) {
            return $template->render($data);
        });
    }

    protected function createSandbox(): TemplateSandbox
    {
        return new TemplateSandbox(
            $this->security,
            $this->config['sandbox'] ?? []
        );
    }
}

// Sandbox for isolated template execution
class TemplateSandbox
{
    private SecurityManager $security;
    private array $config;

    public function execute(callable $render): string
    {
        // Apply security restrictions
        $this->security->restrictEnvironment();
        
        try {
            // Execute with resource limits
            return $this->executeWithLimits($render);
            
        } finally {
            // Always restore environment
            $this->security->restoreEnvironment();
        }
    }

    protected function executeWithLimits(callable $render): string
    {
        // Apply resource limits
        $this->applyLimits();
        
        // Execute with timeout
        return $this->executeWithTimeout($render, $this->config['timeout'] ?? 5);
    }
}
