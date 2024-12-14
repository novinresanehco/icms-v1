<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, Storage};
use App\Core\Security\SecurityManager;
use App\Core\Template\Events\TemplateEvent;

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManager $security;
    private AuditLogger $auditLogger;
    private TemplateCompiler $compiler;
    private TemplateCache $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityManager $security,
        AuditLogger $auditLogger,
        TemplateCompiler $compiler,
        TemplateCache $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->compiler = $compiler;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function render(string $template, array $data = []): string 
    {
        return DB::transaction(function() use ($template, $data) {
            try {
                // Security validation
                $this->validateTemplateAccess($template);
                $this->validateTemplateData($data);

                // Get compiled template
                $compiled = $this->getCompiledTemplate($template);

                // Render with security context
                $rendered = $this->renderSecurely($compiled, $data);

                // Log successful render
                $this->auditLogger->logTemplateRender($template);

                return $rendered;

            } catch (Exception $e) {
                $this->handleRenderError($e, $template);
                throw $e;
            }
        });
    }

    private function validateTemplateAccess(string $template): void 
    {
        if (!$this->security->checkPermission('template.render', $template)) {
            throw new TemplateAccessException('Unauthorized template access');
        }
    }

    private function validateTemplateData(array $data): void 
    {
        $validated = $this->validator->validate($data, [
            'content' => 'array',
            'meta' => 'array',
            'user' => 'array'
        ]);

        if (!$validated) {
            throw new ValidationException('Invalid template data');
        }
    }

    private function getCompiledTemplate(string $template): CompiledTemplate 
    {
        // Try to get from cache
        $cacheKey = "template:compiled:{$template}";
        
        return Cache::remember($cacheKey, 3600, function() use ($template) {
            // Load template
            $source = $this->loadTemplate($template);
            
            // Compile with security checks
            return $this->compiler->compile($source);
        });
    }

    private function loadTemplate(string $template): string 
    {
        $path = $this->resolveTemplatePath($template);
        
        if (!Storage::exists($path)) {
            throw new TemplateNotFoundException("Template not found: {$template}");
        }

        return Storage::get($path);
    }

    private function renderSecurely(CompiledTemplate $compiled, array $data): string 
    {
        // Create secure rendering context
        $context = new RenderingContext($compiled, $data);

        // Apply security policies
        $this->applySecurityPolicies($context);

        // Render with sandbox
        return $this->renderInSandbox($context);
    }

    private function applySecurityPolicies(RenderingContext $context): void 
    {
        // Apply XSS protection
        $context->enableXssProtection();

        // Apply CSP headers
        $context->setContentSecurityPolicy();

        // Restrict dangerous functions
        $context->setFunctionBlacklist([
            'eval', 'exec', 'shell_exec', 'system',
            'passthru', 'popen', 'proc_open'
        ]);
    }

    private function renderInSandbox(RenderingContext $context): string 
    {
        // Create isolated environment
        $sandbox = new TemplateSandbox($context);

        // Render with resource limits
        return $sandbox->render([
            'memory_limit' => '128M',
            'time_limit' => 5,
            'disable_functions' => $context->getFunctionBlacklist()
        ]);
    }

    public function createTemplate(TemplateRequest $request): Template 
    {
        return DB::transaction(function() use ($request) {
            try {
                // Validate request
                $this->validateTemplateRequest($request);

                // Process template
                $template = $this->processTemplate($request);

                // Store template
                $template = $this->storeTemplate($template);

                // Clear relevant caches
                $this->clearTemplateCaches($template);

                // Log creation
                $this->auditLogger->logTemplateCreation($template);

                return $template;

            } catch (Exception $e) {
                $this->handleTemplateError($e, $request);
                throw $e;
            }
        });
    }

    private function validateTemplateRequest(TemplateRequest $request): void 
    {
        $validated = $this->validator->validate($request, [
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|in:page,partial,layout',
            'meta' => 'array'
        ]);

        if (!$validated) {
            throw new ValidationException('Invalid template request');
        }
    }

    private function processTemplate(TemplateRequest $request): Template 
    {
        // Create template instance
        $template = new Template([
            'name' => $this->sanitizeName($request->name),
            'content' => $this->sanitizeContent($request->content),
            'type' => $request->type,
            'meta' => $this->processMetaData($request->meta)
        ]);

        // Pre-compile to validate syntax
        $this->compiler->validate($template->content);

        return $template;
    }

    private function storeTemplate(Template $template): Template 
    {
        $template->save();

        // Store in file system
        $path = $this->resolveTemplatePath($template->name);
        Storage::put($path, $template->content);

        return $template;
    }

    private function clearTemplateCaches(Template $template): void 
    {
        $this->cache->clear("template:compiled:{$template->name}");
        $this->cache->clear("template:metadata:{$template->name}");
    }

    private function sanitizeName(string $name): string 
    {
        return preg_replace('/[^a-zA-Z0-9\-_.]/', '', $name);
    }

    private function sanitizeContent(string $content): string 
    {
        return $this->security->sanitizeHtml($content);
    }

    private function resolveTemplatePath(string $template): string 
    {
        return "templates/" . $this->sanitizeName($template) . ".blade.php";
    }

    private function handleRenderError(Exception $e, string $template): void 
    {
        $this->auditLogger->logTemplateError($e, $template);

        if ($e instanceof SecurityException) {
            event(new TemplateSecurityEvent($e, $template));
        }
    }

    private function handleTemplateError(Exception $e, TemplateRequest $request): void 
    {
        $this->auditLogger->logTemplateError($e, $request);

        if ($e instanceof SecurityException) {
            event(new TemplateSecurityEvent($e, $request));
        }
    }
}
