namespace App\Core\Template;

class TemplateManager implements TemplateManagerInterface 
{
    protected CacheManager $cache;
    protected SecurityManager $security;
    protected ValidationService $validator;
    
    public function __construct(
        CacheManager $cache,
        SecurityManager $security,
        ValidationService $validator
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
    }

    public function render(string $template, array $data = []): string 
    {
        return $this->cache->remember(
            $this->getCacheKey($template, $data),
            function() use ($template, $data) {
                // Validate template and data
                $this->validator->validateTemplate($template);
                $this->validator->validateData($data);
                
                // Create secure render context
                $context = $this->createRenderContext($template, $data);
                
                // Execute render within protected context
                return $this->executeRender($context);
            }
        );
    }

    protected function createRenderContext(string $template, array $data): RenderContext 
    {
        return new RenderContext(
            template: $template,
            data: $this->sanitizeData($data),
            security: $this->security->createTemplateContext()
        );
    }

    protected function executeRender(RenderContext $context): string 
    {
        try {
            // Execute within transaction for rollback capability
            return DB::transaction(function() use ($context) {
                // Pre-render validation
                $this->security->validateRenderContext($context);
                
                // Compile template
                $compiled = $this->compileTemplate($context->template);
                
                // Render with data
                $rendered = $this->renderCompiled($compiled, $context->data);
                
                // Post-render validation
                $this->validator->validateOutput($rendered);
                
                return $rendered;
            });
        } catch (\Exception $e) {
            $this->handleRenderError($e, $context);
            throw $e;
        }
    }

    protected function compileTemplate(string $template): CompiledTemplate 
    {
        $compiler = new TemplateCompiler($this->security);
        return $compiler->compile($template);
    }

    protected function renderCompiled(CompiledTemplate $compiled, array $data): string 
    {
        $renderer = new TemplateRenderer($this->security);
        return $renderer->render($compiled, $data);
    }

    protected function sanitizeData(array $data): array 
    {
        return array_map(
            fn($value) => $this->security->sanitizeValue($value),
            $data
        );
    }

    protected function getCacheKey(string $template, array $data): string 
    {
        return 'template:' . md5($template . serialize($data));
    }

    protected function handleRenderError(\Exception $e, RenderContext $context): void 
    {
        Log::error('Template render failed', [
            'exception' => $e->getMessage(),
            'template' => $context->template,
            'data' => $context->data
        ]);
    }
}
