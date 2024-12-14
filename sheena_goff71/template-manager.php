namespace App\Core\Template;

class TemplateManager implements TemplateInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private CompilationEngine $compiler;
    private ValidationService $validator;
    private VersionManager $versions;
    private array $config;

    public function render(string $template, array $data = []): string 
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation($template),
            function() use ($template, $data) {
                // Validate template
                $this->validateTemplate($template);
                
                // Get compiled template
                $compiled = $this->getCompiledTemplate($template);
                
                // Validate data
                $data = $this->validateData($data);
                
                // Create sandbox
                $sandbox = $this->createRenderSandbox();
                
                try {
                    // Render in sandbox
                    $output = $sandbox->render($compiled, $data);
                    
                    // Validate output
                    return $this->validateOutput($output);
                    
                } finally {
                    // Cleanup sandbox
                    $sandbox->cleanup();
                }
            }
        );
    }

    public function compile(string $template): CompiledTemplate 
    {
        return $this->security->executeCriticalOperation(
            new CompileTemplateOperation($template),
            function() use ($template) {
                // Parse template
                $ast = $this->compiler->parse($template);
                
                // Validate syntax
                $this->validateSyntax($ast);
                
                // Apply security rules
                $this->applySecurityRules($ast);
                
                // Generate code
                $code = $this->compiler->generate($ast);
                
                // Create version
                $version = $this->versions->create($template, $code);
                
                return new CompiledTemplate($code, $version);
            }
        );
    }

    public function extend(string $name, callable $extension): void 
    {
        $this->security->executeCriticalOperation(
            new ExtendTemplateOperation($name),
            function() use ($name, $extension) {
                // Validate extension
                $this->validateExtension($extension);
                
                // Register extension
                $this->compiler->registerExtension($name, $extension);
                
                // Clear related caches
                $this->clearExtensionCache($name);
            }
        );
    }

    protected function validateTemplate(string $template): void 
    {
        if (!$this->validator->isValidTemplate($template)) {
            throw new InvalidTemplateException();
        }

        if ($this->containsMaliciousCode($template)) {
            throw new MaliciousTemplateException();
        }
    }

    protected function getCompiledTemplate(string $template): CompiledTemplate 
    {
        $cacheKey = $this->getCacheKey($template);
        
        return $this->cache->remember($cacheKey, function() use ($template) {
            return $this->compile($template);
        });
    }

    protected function validateData(array $data): array 
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->escapeString($value);
            }
            if (is_array($value)) {
                return $this->validateData($value);
            }
            return $value;
        }, $data);
    }

    protected function createRenderSandbox(): TemplateSandbox 
    {
        return new TemplateSandbox([
            'memory_limit' => $this->config['sandbox_memory_limit'],
            'max_execution_time' => $this->config['sandbox_timeout'],
            'allowed_classes' => $this->config['sandbox_allowed_classes'],
            'allowed_functions' => $this->config['sandbox_allowed_functions']
        ]);
    }

    protected function validateOutput(string $output): string 
    {
        // XSS prevention
        $output = $this->sanitizeOutput($output);
        
        // Content security
        $this->validateContentSecurity($output);
        
        // Size limits
        if (strlen($output) > $this->config['max_output_size']) {
            throw new OutputSizeLimitException();
        }
        
        return $output;
    }

    protected function validateSyntax(TemplateAst $ast): void 
    {
        foreach ($this->config['syntax_rules'] as $rule) {
            if (!$rule->validate($ast)) {
                throw new TemplateSyntaxException($rule->getMessage());
            }
        }
    }

    protected function applySecurityRules(TemplateAst $ast): void 
    {
        foreach ($this->config['security_rules'] as $rule) {
            $rule->apply($ast);
        }
    }

    protected function containsMaliciousCode(string $template): bool 
    {
        foreach ($this->config['malicious_patterns'] as $pattern) {
            if (preg_match($pattern, $template)) {
                return true;
            }
        }
        return false;
    }

    protected function escapeString(string $value): string 
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    protected function sanitizeOutput(string $output): string 
    {
        // Remove potentially dangerous content
        $output = $this->removeDangerousTags($output);
        
        // Encode special characters
        $output = $this->escapeString($output);
        
        // Apply content security policy
        $output = $this->applyCSP($output);
        
        return $output;
    }

    protected function validateContentSecurity(string $output): void 
    {
        if ($this->containsUnauthorizedContent($output)) {
            throw new UnauthorizedContentException();
        }
    }

    protected function getCacheKey(string $template): string 
    {
        return sprintf(
            'template.%s.%s',
            md5($template),
            $this->config['version']
        );
    }
}
