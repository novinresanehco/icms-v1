namespace App\Core\Template;

class TemplateEngine
{
    private SecurityManager $security;
    private CacheManager $cache;
    private CompilationService $compiler;
    private ValidationService $validator;
    private AuditLogger $audit;
    
    private const CACHE_TTL = 3600;
    private const MAX_RENDER_TIME = 5;
    private const MEMORY_LIMIT = '128M';

    public function render(string $template, array $data, ?User $user = null): string
    {
        return $this->security->executeCriticalOperation(function() use ($template, $data, $user) {
            // Create render context
            $context = $this->createRenderContext($template, $data, $user);
            
            // Validate context and permissions
            $this->validateRenderContext($context);
            
            try {
                // Get compiled template
                $compiled = $this->getCompiledTemplate($template);
                
                // Render in sandbox
                $output = $this->renderInSandbox($compiled, $context);
                
                // Post-render validation
                $this->validateOutput($output);
                
                // Log successful render
                $this->audit->logTemplateRender($template, $user);
                
                return $output;
                
            } catch (Exception $e) {
                $this->handleRenderError($e, $context);
                throw $e;
            }
        });
    }

    protected function createRenderContext(string $template, array $data, ?User $user): RenderContext
    {
        return new RenderContext([
            'template' => $template,
            'data' => $this->validator->validateData($data),
            'user' => $user,
            'timestamp' => now(),
            'memory_limit' => self::MEMORY_LIMIT,
            'time_limit' => self::MAX_RENDER_TIME
        ]);
    }

    protected function validateRenderContext(RenderContext $context): void
    {
        // Validate template access
        if (!$this->canAccessTemplate($context->getTemplate(), $context->getUser())) {
            throw new UnauthorizedException('Template access denied');
        }

        // Validate data structure
        if (!$this->validator->validateTemplateData($context->getData())) {
            throw new ValidationException('Invalid template data');
        }

        // Check resource limits
        if (!$this->checkResourceLimits($context)) {
            throw new ResourceLimitException('Resource limits exceeded');
        }
    }

    protected function getCompiledTemplate(string $template): CompiledTemplate
    {
        return $this->cache->tags("template.{$template}")->remember(
            "compiled.{$template}",
            self::CACHE_TTL,
            fn() => $this->compiler->compile($template)
        );
    }

    protected function renderInSandbox(CompiledTemplate $compiled, RenderContext $context): string
    {
        $sandbox = new TemplateSandbox([
            'memory_limit' => $context->getMemoryLimit(),
            'time_limit' => $context->getTimeLimit(),
            'allowed_functions' => $this->getAllowedFunctions(),
            'context' => $context
        ]);

        return $sandbox->execute($compiled, $context->getData());
    }

    protected function validateOutput(string $output): void
    {
        // Sanitize output
        $sanitized = $this->sanitizeOutput($output);
        
        // Validate structure
        if (!$this->validator->validateOutput($sanitized)) {
            throw new ValidationException('Invalid template output');
        }
        
        // Check security constraints
        if (!$this->validator->validateSecurity($sanitized)) {
            throw new SecurityException('Output security validation failed');
        }
    }

    protected function canAccessTemplate(string $template, ?User $user): bool
    {
        if ($this->isPublicTemplate($template)) {
            return true;
        }

        return $user && (
            $user->isAdmin() ||
            $user->can("access_template.{$template}")
        );
    }

    protected function checkResourceLimits(RenderContext $context): bool
    {
        // Check memory usage
        if (memory_get_usage() > $this->parseMemoryLimit($context->getMemoryLimit())) {
            return false;
        }

        // Check CPU time
        if (sys_getloadavg()[0] > 0.8) {
            return false;
        }

        return true;
    }

    protected function sanitizeOutput(string $output): string
    {
        // Remove potentially harmful content
        $output = $this->security->sanitize($output);
        
        // Encode special characters
        $output = htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
        
        // Add security headers
        $this->addSecurityHeaders();
        
        return $output;
    }

    protected function handleRenderError(Exception $e, RenderContext $context): void
    {
        // Log error
        $this->audit->logRenderError($e, $context);
        
        // Clean up resources
        $this->cleanup($context);
        
        // Notify if critical
        if ($this->isCriticalError($e)) {
            $this->notifyAdministrators($e, $context);
        }
    }

    protected function getAllowedFunctions(): array
    {
        return [
            'date',
            'number_format',
            'strlen',
            'strtolower',
            'strtoupper',
            'trim',
            'count'
        ];
    }

    protected function addSecurityHeaders(): void
    {
        header('Content-Security-Policy: default-src \'self\'');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
    }
}
