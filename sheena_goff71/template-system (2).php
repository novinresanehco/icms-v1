<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Template\Events\TemplateEvent;
use App\Core\Template\Exceptions\{TemplateException, CompilationException};
use Illuminate\Support\Facades\{Cache, View, Storage, Log};
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class TemplateManager
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected CacheManager $cache;
    protected AuditLogger $auditLogger;
    protected CompilationEngine $compiler;

    private const CACHE_TTL = 3600;
    private const MAX_COMPILE_TIME = 5;
    private const TEMPLATE_PATH = 'templates';

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        AuditLogger $auditLogger,
        CompilationEngine $compiler
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
        $this->compiler = $compiler;
    }

    /**
     * Register new template with security validation
     */
    public function registerTemplate(array $data, User $user): Template
    {
        return $this->security->executeCriticalOperation(function() use ($data, $user) {
            // Validate template data
            $validatedData = $this->validator->validateTemplate($data);
            
            // Compile template to verify syntax
            $compiledTemplate = $this->compiler->compileTemplate($validatedData['content']);
            
            // Create template record
            $template = DB::transaction(function() use ($validatedData, $compiledTemplate, $user) {
                $template = new Template([
                    'name' => $validatedData['name'],
                    'description' => $validatedData['description'],
                    'content' => $validatedData['content'],
                    'compiled_content' => $compiledTemplate,
                    'created_by' => $user->id
                ]);
                
                $template->save();
                
                // Store compiled version
                $this->storeCompiledTemplate($template, $compiledTemplate);
                
                return $template;
            });
            
            // Clear template caches
            $this->cache->tags(['templates'])->flush();
            
            // Log template creation
            $this->auditLogger->logTemplateCreation($template, $user);
            
            return $template;
        }, ['context' => 'template_registration', 'user_id' => $user->id]);
    }

    /**
     * Render template with content and caching
     */
    public function renderTemplate(int $templateId, array $data, ?User $user = null): string
    {
        $cacheKey = "template.render.$templateId." . md5(serialize($data));
        
        return $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($templateId, $data, $user) {
            // Get template with security check
            $template = $this->getTemplate($templateId, $user);
            
            // Validate render data
            $validatedData = $this->validator->validateRenderData($data);
            
            // Load compiled template
            $compiled = $this->loadCompiledTemplate($template);
            
            // Render with security context
            return $this->security->executeCriticalOperation(function() use ($compiled, $validatedData) {
                $startTime = microtime(true);
                
                try {
                    // Render template with provided data
                    $rendered = View::make('template::dynamic', [
                        'template' => $compiled,
                        'data' => $validatedData
                    ])->render();
                    
                    // Check render time
                    if ((microtime(true) - $startTime) > self::MAX_COMPILE_TIME) {
                        Log::warning('Template render time exceeded', [
                            'template_id' => $template->id,
                            'render_time' => microtime(true) - $startTime
                        ]);
                    }
                    
                    return $rendered;
                } catch (\Throwable $e) {
                    throw new TemplateException('Template rendering failed: ' . $e->getMessage());
                }
            }, ['context' => 'template_render', 'template_id' => $templateId]);
        });
    }

    /**
     * Store compiled template securely
     */
    protected function storeCompiledTemplate(Template $template, string $compiled): void
    {
        $path = $this->getTemplatePath($template);
        
        Storage::put($path, $compiled);
    }

    /**
     * Load compiled template with security checks
     */
    protected function loadCompiledTemplate(Template $template): string
    {
        $path = $this->getTemplatePath($template);
        
        try {
            $compiled = Storage::get($path);
            
            if (!$compiled) {
                // Recompile if missing
                $compiled = $this->compiler->compileTemplate($template->content);
                $this->storeCompiledTemplate($template, $compiled);
            }
            
            return $compiled;
        } catch (FileNotFoundException $e) {
            throw new TemplateException('Compiled template not found');
        }
    }

    /**
     * Generate secure template path
     */
    protected function getTemplatePath(Template $template): string
    {
        return self::TEMPLATE_PATH . '/' . $template->id . '_' . md5($template->updated_at);
    }

    /**
     * Get template with security validation
     */
    protected function getTemplate(int $id, ?User $user): Template
    {
        $template = Template::findOrFail($id);
        
        // Verify read permissions
        if ($user && !$this->security->canAccess($user, 'template.view', $template)) {
            throw new TemplateException('Unauthorized template access attempt');
        }
        
        return $template;
    }

    /**
     * Clear template cache
     */
    public function clearTemplateCache(Template $template): void
    {
        $this->cache->tags(['templates'])->flush();
        $this->auditLogger->logCacheClear('template', $template->id);
    }
}
