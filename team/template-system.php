namespace App\Core\Template;

class TemplateManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ThemeRepository $themes;
    private CompilerService $compiler;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ThemeRepository $themes,
        CompilerService $compiler,
        ValidationService $validator,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->themes = $themes;
        $this->compiler = $compiler;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function render(string $template, array $data, ?User $user = null): string 
    {
        return $this->security->executeCriticalOperation(
            function() use ($template, $data, $user) {
                // Validate template and data
                $this->validator->validateTemplate($template);
                $this->validator->validateData($data);

                // Get compiled template from cache or compile
                $compiled = $this->cache->remember(
                    "template.{$template}",
                    3600,
                    fn() => $this->compiler->compile($template)
                );

                // Secure context creation
                $context = new RenderContext($compiled, $data, $user);

                // Render with security checks
                $rendered = $this->renderSecure($context);

                // Audit trail
                $this->audit->logTemplateRender($template, $user);

                return $rendered;
            },
            new SecurityContext($user, 'template.render')
        );
    }

    public function updateTheme(int $themeId, array $data, User $user): Theme
    {
        return $this->security->executeCriticalOperation(
            function() use ($themeId, $data, $user) {
                $theme = $this->themes->findOrFail($themeId);

                // Verify permissions
                if (!$this->canModifyTheme($theme, $user)) {
                    throw new AuthorizationException();
                }

                // Validate theme data
                $validated = $this->validator->validate($data, 'theme.update');

                // Update theme
                $theme->update($validated);

                // Clear theme caches
                $this->cache->tags(['themes', "theme.{$themeId}"])->flush();

                // Audit trail
                $this->audit->logThemeUpdate($theme, $user);

                return $theme->fresh();
            },
            new SecurityContext($user, 'theme.update')
        );
    }

    protected function renderSecure(RenderContext $context): string
    {
        try {
            // Create sandboxed environment
            $sandbox = $this->createSandbox($context);

            // Execute template with timeout and memory limits
            $output = $sandbox->execute($context->getCompiled(), $context->getData());

            // Sanitize output
            return $this->sanitizeOutput($output);

        } catch (Exception $e) {
            $this->handleRenderError($e, $context);
            throw $e;
        }
    }

    protected function createSandbox(RenderContext $context): TemplateSandbox
    {
        return new TemplateSandbox([
            'timeout' => 5, // 5 second execution timeout
            'memory_limit' => '128M',
            'allowed_functions' => $this->getAllowedFunctions(),
            'context' => $context
        ]);
    }

    protected function sanitizeOutput(string $output): string
    {
        return $this->compiler->sanitize($output);
    }

    protected function canModifyTheme(Theme $theme, User $user): bool
    {
        return $user->isAdmin() || 
               $theme->user_id === $user->id || 
               $theme->hasEditPermission($user);
    }

    protected function getAllowedFunctions(): array
    {
        return [
            // Whitelist of safe template functions
            'date',
            'count',
            'strlen',
            'strtolower',
            'strtoupper',
            'number_format'
        ];
    }

    protected function handleRenderError(Exception $e, RenderContext $context): void
    {
        // Log error details
        $this->audit->logTemplateError($e, $context);

        // Clean up resources
        $this->cleanup($context);
    }

    protected function cleanup(RenderContext $context): void
    {
        // Clean temporary files
        // Release resources
        // Reset sandbox state
    }
}

class TemplateSandbox
{
    private array $config;
    private array $allowedFunctions;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->allowedFunctions = $config['allowed_functions'];
    }

    public function execute(string $compiled, array $data): string
    {
        // Set resource limits
        $this->setResourceLimits();

        try {
            // Create isolated scope
            return $this->runInIsolation($compiled, $data);
        } finally {
            // Reset resource limits
            $this->resetResourceLimits();
        }
    }

    protected function setResourceLimits(): void
    {
        set_time_limit($this->config['timeout']);
        ini_set('memory_limit', $this->config['memory_limit']);
    }

    protected function runInIsolation(string $compiled, array $data): string
    {
        $__template = $compiled;
        $__data = $data;
        $__allowed = $this->allowedFunctions;

        return (function() use ($__template, $__data, $__allowed) {
            // Extract data into symbol table
            extract($__data);

            // Buffer output
            ob_start();
            
            try {
                eval('?>' . $__template);
                return ob_get_clean();
            } catch (Exception $e) {
                ob_end_clean();
                throw $e;
            }
        })();
    }

    protected function resetResourceLimits(): void
    {
        // Reset to default values
        set_time_limit(30);
        ini_set('memory_limit', '256M');
    }
}
