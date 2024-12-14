namespace App\Core\Template;

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManager $security;
    private TemplateRepository $repository;
    private CacheManager $cache;
    private Compiler $compiler;
    private ValidatorService $validator;
    private ThemeRegistry $themes;

    public function __construct(
        SecurityManager $security,
        TemplateRepository $repository,
        CacheManager $cache,
        Compiler $compiler,
        ValidatorService $validator,
        ThemeRegistry $themes
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->compiler = $compiler;
        $this->validator = $validator;
        $this->themes = $themes;
    }

    public function render(string $template, array $data = []): string 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRender($template, $data),
            new SecurityContext('template.render', ['template' => $template])
        );
    }

    private function executeRender(string $template, array $data): string 
    {
        // Validate template and data
        $this->validator->validateTemplate($template);
        $this->validator->validateData($data);

        // Get compiled template from cache or compile
        $compiled = $this->cache->remember(
            "template.{$template}",
            config('template.cache_ttl'),
            fn() => $this->compiler->compile($this->repository->get($template))
        );

        // Execute template in isolated environment
        return $this->executeTemplate($compiled, $this->sanitizeData($data));
    }

    private function executeTemplate(string $compiled, array $data): string 
    {
        try {
            // Create isolated scope
            return (function() use ($compiled, $data) {
                extract($data);
                ob_start();
                eval('?>' . $compiled);
                return ob_get_clean();
            })();
        } catch (\Throwable $e) {
            throw new TemplateExecutionException(
                "Template execution failed: {$e->getMessage()}"
            );
        }
    }

    public function compileTheme(string $theme): void 
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeThemeCompilation($theme),
            new SecurityContext('theme.compile', ['theme' => $theme])
        );
    }

    private function executeThemeCompilation(string $theme): void 
    {
        $themeConfig = $this->themes->get($theme);
        
        DB::beginTransaction();
        try {
            // Compile all theme templates
            foreach ($themeConfig->getTemplates() as $template) {
                $compiled = $this->compiler->compile($template);
                $this->repository->store($template->getName(), $compiled);
                
                // Cache compiled template
                $this->cache->put(
                    "template.{$template->getName()}", 
                    $compiled,
                    config('template.cache_ttl')
                );
            }

            // Compile theme assets
            $this->compileThemeAssets($themeConfig);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ThemeCompilationException(
                "Theme compilation failed: {$e->getMessage()}"
            );
        }
    }

    private function compileThemeAssets(ThemeConfig $config): void 
    {
        foreach ($config->getAssets() as $asset) {
            // Compile and minify CSS/JS
            $compiled = $this->compiler->compileAsset($asset);
            
            // Store with version hash
            $hash = hash('xxh3', $compiled);
            $this->repository->storeAsset("{$asset->getName()}.{$hash}", $compiled);
        }
    }

    public function validateTheme(string $theme): ValidationResult 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeThemeValidation($theme),
            new SecurityContext('theme.validate', ['theme' => $theme])
        );
    }

    private function executeThemeValidation(string $theme): ValidationResult 
    {
        $config = $this->themes->get($theme);
        
        // Validate theme structure
        $structureValid = $this->validator->validateThemeStructure($config);
        
        // Validate templates
        $templateResults = [];
        foreach ($config->getTemplates() as $template) {
            $templateResults[$template->getName()] = 
                $this->validator->validateTemplate($template);
        }
        
        // Validate assets
        $assetResults = [];
        foreach ($config->getAssets() as $asset) {
            $assetResults[$asset->getName()] = 
                $this->validator->validateAsset($asset);
        }
        
        return new ValidationResult($structureValid, $templateResults, $assetResults);
    }

    private function sanitizeData(array $data): array 
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $sanitized[$key] = is_string($value) ? 
                htmlspecialchars($value, ENT_QUOTES | ENT_HTML5) : 
                $value;
        }
        return $sanitized;
    }
}
