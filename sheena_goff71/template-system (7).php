namespace App\Core\Template;

/**
 * Core template rendering engine with strict validation and caching
 */
class TemplateManager implements TemplateManagerInterface
{
    private CacheManager $cache;
    private ValidationService $validator;
    private SecurityManager $security;
    private array $registeredComponents = [];
    
    public function __construct(
        CacheManager $cache,
        ValidationService $validator,
        SecurityManager $security
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->security = $security;
    }

    /**
     * Renders a template with comprehensive validation and caching
     *
     * @throws TemplateException
     * @throws SecurityException
     */
    public function render(string $template, array $data = []): string
    {
        try {
            // Validate template and data
            $this->validateTemplate($template);
            $this->validateData($data);

            // Generate cache key
            $cacheKey = $this->generateCacheKey($template, $data);

            // Try to get from cache
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached;
            }

            // Compile and render template
            $compiled = $this->compile($template);
            $rendered = $this->renderCompiled($compiled, $data);

            // Security check on output
            $this->security->validateOutput($rendered);

            // Cache the result
            $this->cache->put($cacheKey, $rendered, config('template.cache_ttl'));

            return $rendered;

        } catch (\Exception $e) {
            throw new TemplateException(
                'Template rendering failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Registers a component for use in templates
     * 
     * @throws ComponentException
     */
    public function registerComponent(string $name, ComponentInterface $component): void
    {
        if (isset($this->registeredComponents[$name])) {
            throw new ComponentException("Component '$name' already registered");
        }

        // Validate component
        $this->validator->validateComponent($component);
        
        $this->registeredComponents[$name] = $component;
    }

    /**
     * Compiles a template to PHP code with security checks
     */
    private function compile(string $template): string
    {
        // Security scan on template code
        $this->security->scanTemplate($template);

        return $this->compiler->compile($template);
    }

    /**
     * Renders compiled template code with data
     */
    private function renderCompiled(string $compiled, array $data): string
    {
        // Create isolated scope
        return (function() use ($compiled, $data) {
            extract($data);
            ob_start();
            eval('?>' . $compiled);
            return ob_get_clean();
        })();
    }

    /**
     * Validates template structure and security
     */
    private function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplateStructure($template)) {
            throw new ValidationException('Invalid template structure');
        }

        if (!$this->security->validateTemplateContent($template)) {
            throw new SecurityException('Template failed security validation');
        }
    }

    /**
     * Validates template data
     */
    private function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->validator->validateDataValue($value)) {
                throw new ValidationException("Invalid data value for key: $key");
            }
        }
    }

    /**
     * Generates secure cache key for template
     */
    private function generateCacheKey(string $template, array $data): string
    {
        return hash('sha256', $template . serialize($data));
    }
}
