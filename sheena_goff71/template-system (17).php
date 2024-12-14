namespace App\Core\Template;

class TemplateManager implements TemplateManagerInterface 
{
    private CacheManager $cache;
    private SecurityManager $security;
    private ValidationService $validator;
    private TemplateRepository $repository;

    public function __construct(
        CacheManager $cache,
        SecurityManager $security,
        ValidationService $validator,
        TemplateRepository $repository
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
        $this->repository = $repository;
    }

    /**
     * Renders a template with security and caching
     *
     * @throws TemplateException On render failure
     * @throws SecurityException On security validation failure
     */
    public function render(string $template, array $data = []): string 
    {
        try {
            // Critical section - requires protection
            return $this->security->executeProtected(function() use ($template, $data) {
                // Validate template and data
                $this->validateTemplateRequest($template, $data);
                
                // Check cache first
                $cacheKey = $this->getCacheKey($template, $data);
                if ($cached = $this->cache->get($cacheKey)) {
                    return $cached;
                }
                
                // Process and render template
                $processed = $this->processTemplate($template, $data);
                
                // Cache result with security context
                $this->cache->put($cacheKey, $processed, $this->getCacheDuration($template));
                
                return $processed;
            });
        } catch (\Exception $e) {
            $this->handleError($e, $template);
            throw new TemplateException('Template render failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validates template request parameters
     *
     * @throws ValidationException On validation failure
     */
    protected function validateTemplateRequest(string $template, array $data): void
    {
        // Validate template exists and is accessible
        if (!$this->repository->exists($template)) {
            throw new ValidationException('Template not found or inaccessible');
        }

        // Validate template data
        $rules = $this->repository->getValidationRules($template);
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Invalid template data');
        }
    }

    /**
     * Processes template with security measures
     */
    protected function processTemplate(string $template, array $data): string
    {
        // Load template with security checks
        $content = $this->repository->load($template);

        // Process template content
        $output = $this->compileTemplate($content, $data);
        
        // Security: Sanitize output
        return $this->security->sanitizeOutput($output);
    }

    /**
     * Compiles template with error handling
     */
    protected function compileTemplate(string $content, array $data): string
    {
        try {
            // Extract data in isolated scope
            extract($data, EXTR_SKIP);
            
            // Capture output
            ob_start();
            eval('?>' . $content);
            return ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new TemplateException('Template compilation failed', 0, $e);
        }
    }

    /**
     * Handles template errors with logging
     */
    protected function handleError(\Exception $e, string $template): void
    {
        // Log error with context
        Log::error('Template render failed', [
            'template' => $template,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }

    protected function getCacheDuration(string $template): int
    {
        return $this->repository->getCacheDuration($template);
    }
}
