<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityContext;
use App\Core\Security\XSSProtection;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;

class TemplateEngineService implements TemplateEngineInterface
{
    private const CACHE_TTL = 3600; // 1 hour
    private const MAX_TEMPLATE_SIZE = 5242880; // 5MB

    private TemplateRepository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private XSSProtection $xssProtection;
    private ThemeManager $themeManager;

    public function __construct(
        TemplateRepository $repository,
        CacheManager $cache,
        ValidationService $validator,
        XSSProtection $xssProtection,
        ThemeManager $themeManager
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->xssProtection = $xssProtection;
        $this->themeManager = $themeManager;
    }

    public function renderTemplate(string $templateId, array $data, SecurityContext $context): TemplateResult
    {
        try {
            // Verify template access
            $template = $this->getTemplate($templateId, $context);
            
            // Validate template data
            $validatedData = $this->validateTemplateData($data, $template->getSchema());

            // Process and sanitize data
            $processedData = $this->preprocessData($validatedData);

            // Get cached if available
            $cacheKey = $this->generateCacheKey($templateId, $processedData);
            if ($cached = $this->getCachedTemplate($cacheKey)) {
                return new TemplateResult($cached, true);
            }

            // Render template with security checks
            $rendered = $this->renderSecurely($template, $processedData);

            // Cache the result
            $this->cacheTemplate($cacheKey, $rendered);

            return new TemplateResult($rendered, true);

        } catch (\Exception $e) {
            throw new TemplateEngineException('Template rendering failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function compileTemplate(string $source, SecurityContext $context): CompiledTemplate
    {
        try {
            // Validate template size
            if (strlen($source) > self::MAX_TEMPLATE_SIZE) {
                throw new TemplateSizeException('Template exceeds maximum allowed size');
            }

            // Validate template syntax
            $this->validator->validateTemplateSyntax($source);

            // Parse and compile template
            $compiled = $this->parseAndCompile($source);

            // Validate compiled output
            $this->validateCompiledTemplate($compiled);

            return new CompiledTemplate($compiled);

        } catch (\Exception $e) {
            throw new TemplateCompilationException('Template compilation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function registerComponent(string $name, callable $renderer, array $schema): void
    {
        $this->validateComponentName($name);
        $this->validateComponentSchema($schema);

        $this->repository->registerComponent($name, $renderer, $schema);
    }

    private function getTemplate(string $templateId, SecurityContext $context): Template
    {
        $template = $this->repository->findOrFail($templateId);
        
        if (!$this->canAccessTemplate($template, $context)) {
            throw new TemplateAccessException('Unauthorized template access');
        }

        return $template;
    }

    private function validateTemplateData(array $data, array $schema): array
    {
        return $this->validator->validateAgainstSchema($data, $schema);
    }

    private function preprocessData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->xssProtection->clean($value);
            }
            return $value;
        }, $data);
    }

    private function renderSecurely(Template $template, array $data): string
    {
        // Create isolated rendering environment
        $renderer = $this->createSecureRenderer();
        
        // Apply theme variables
        $themeData = $this->themeManager->getThemeVariables($template->getTheme());
        
        // Merge theme data with template data
        $mergedData = array_merge($themeData, $data);
        
        // Render with timeout protection
        return $renderer->renderWithTimeout($template, $mergedData, 5000); // 5 second timeout
    }

    private function createSecureRenderer(): SecureTemplateRenderer
    {
        return new SecureTemplateRenderer([
            'allowed_tags' => $this->getAllowedTags(),
            'allowed_functions' => $this->getAllowedFunctions(),
            'sandbox_options' => [
                'disable_functions' => ['exec', 'shell_exec', 'system', 'passthru'],
                'disable_classes' => ['ReflectionClass', 'PDO'],
                'memory_limit' => '128M',
                'max_execution_time' => 5
            ]
        ]);
    }

    private function validateCompiledTemplate(CompiledTemplate $compiled): void
    {
        // Check for dangerous patterns
        if ($this->containsDangerousCode($compiled->getCode())) {
            throw new TemplateSecurityException('Template contains potentially dangerous code');
        }

        // Validate resource usage
        if ($compiled->getComplexity() > 100) {
            throw new TemplateComplexityException('Template complexity exceeds allowed limit');
        }
    }

    private function containsDangerousCode(string $code): bool
    {
        $patterns = [
            '/\$_(?:GET|POST|REQUEST|COOKIE|SERVER|ENV|FILES)/',
            '/\beval\b/',
            '/\bexec\b/',
            '/\bshell_exec\b/',
            '/\bsystem\b/',
            '/\bpassthru\b/',
            '/\binclude\b/',
            '/\brequire\b/',
            '/\binclude_once\b/',
            '/\brequire_once\b/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code)) {
                return true;
            }
        }

        return false;
    }

    private function generateCacheKey(string $templateId, array $data): string
    {
        return 'template:' . $templateId . ':' . md5(serialize($data));
    }

    private function getCachedTemplate(string $key): ?string
    {
        return $this->cache->get($key);
    }

    private function cacheTemplate(string $key, string $rendered): void
    {
        $this->cache->put($key, $rendered, self::CACHE_TTL);
    }

    private function canAccessTemplate(Template $template, SecurityContext $context): bool
    {
        return $context->hasPermission('template.view') || 
               $template->isPublic() || 
               $template->getOwnerId() === $context->getUserId();
    }

    private function getAllowedTags(): array
    {
        return [
            'if', 'else', 'elseif', 'endif',
            'for', 'endfor', 'foreach', 'endforeach',
            'include', 'extends', 'block', 'endblock'
        ];
    }

    private function getAllowedFunctions(): array
    {
        return [
            'date', 'count', 'strlen', 'strtolower', 'strtoupper',
            'trim', 'nl2br', 'round', 'floor', 'ceil'
        ];
    }
}
