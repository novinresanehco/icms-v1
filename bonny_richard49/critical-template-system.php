<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManager;
use App\Core\Template\Engines\TemplateEngine;
use App\Core\Template\Validators\ThemeValidator;
use Illuminate\Support\Facades\DB;

class CriticalTemplateManager implements TemplateManagerInterface 
{
    private SecurityManagerInterface $security;
    private TemplateEngine $engine;
    private CacheManager $cache;
    private ThemeValidator $validator;
    private AuditLogger $auditLogger;

    private const CACHE_TTL = 3600; // 1 hour
    private const MAX_TEMPLATE_SIZE = 5242880; // 5MB
    
    public function __construct(
        SecurityManagerInterface $security,
        TemplateEngine $engine,
        CacheManager $cache,
        ThemeValidator $validator,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->engine = $engine;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Render template with security checks and caching
     */
    public function render(string $template, array $data, Context $context): string 
    {
        return $this->security->executeCriticalOperation(
            new TemplateOperation('render', $template),
            function() use ($template, $data, $context) {
                // Check template access
                if (!$this->security->canAccessTemplate($template, $context)) {
                    throw new TemplateAccessException("Unauthorized template access: {$template}");
                }

                // Get from cache if available
                $cacheKey = $this->getCacheKey($template, $data);
                if ($cached = $this->cache->get($cacheKey)) {
                    return $cached;
                }

                // Load and validate template
                $templateContent = $this->loadTemplate($template);
                $this->validator->validateTemplate($templateContent);

                // Sanitize data
                $safeData = $this->sanitizeData($data);

                // Render with security context
                $rendered = $this->engine->render($templateContent, $safeData, $context);

                // Cache result
                $this->cache->put($cacheKey, $rendered, self::CACHE_TTL);

                // Log template usage
                $this->auditLogger->logTemplateRender($template, $context);

                return $rendered;
            }
        );
    }

    /**
     * Install theme with security validation
     */
    public function installTheme(string $theme, array $config): ThemeResult 
    {
        return $this->security->executeCriticalOperation(
            new ThemeOperation('install', $theme),
            function() use ($theme, $config) {
                // Validate theme package
                $this->validator->validateThemePackage($theme, self::MAX_TEMPLATE_SIZE);

                DB::beginTransaction();
                try {
                    // Extract and verify theme
                    $themeFiles = $this->extractTheme($theme);
                    $this->validator->validateThemeStructure($themeFiles);

                    // Install theme files
                    $installed = $this->installThemeFiles($themeFiles, $config);

                    // Clear template cache
                    $this->cache->tags(['templates'])->flush();

                    DB::commit();

                    // Log theme installation
                    $this->auditLogger->logThemeInstall($theme);

                    return new ThemeResult($installed);

                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        );
    }

    /**
     * Load and validate template file
     */
    private function loadTemplate(string $template): string 
    {
        if (!$this->templateExists($template)) {
            throw new TemplateNotFoundException("Template not found: {$template}");
        }

        $content = file_get_contents($this->getTemplatePath($template));
        
        if (strlen($content) > self::MAX_TEMPLATE_SIZE) {
            throw new TemplateSizeException("Template exceeds maximum size");
        }

        return $content;
    }

    /**
     * Sanitize template data
     */
    private function sanitizeData(array $data): array 
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $safe[$key] = $this->sanitizeData($value);
            } else {
                $safe[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
        }
        return $safe;
    }

    /**
     * Generate cache key for template
     */
    private function getCacheKey(string $template, array $data): string 
    {
        return sprintf(
            'template:%s:%s',
            $template,
            md5(serialize($data))
        );
    }

    /**
     * Extract and validate theme package
     */
    private function extractTheme(string $theme): array 
    {
        // Validate ZIP file
        if (!$this->validator->isValidZip($theme)) {
            throw new ThemeException("Invalid theme package");
        }

        // Extract to temp directory
        $tempDir = sys_get_temp_dir() . '/' . uniqid('theme_');
        $zip = new \ZipArchive();
        
        if ($zip->open($theme) !== true) {
            throw new ThemeException("Failed to open theme package");
        }

        $zip->extractTo($tempDir);
        $zip->close();

        // Scan and validate files
        $files = $this->scanThemeFiles($tempDir);
        
        // Clean up temp directory
        $this->cleanup($tempDir);

        return $files;
    }

    /**
     * Install theme files securely
     */
    private function installThemeFiles(array $files, array $config): Theme 
    {
        $theme = new Theme($config);
        
        foreach ($files as $file) {
            // Validate file type and content
            $this->validator->validateThemeFile($file);
            
            // Install file with correct permissions
            $this->installFile($theme, $file);
        }

        return $theme;
    }
}
