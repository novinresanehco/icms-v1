<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationSystem;
use App\Core\CMS\ContentManagementSystem;
use Illuminate\Support\Facades\Cache;

class TemplateSystem implements TemplateInterface
{
    private SecurityManager $security;
    private AuthenticationSystem $auth;
    private ContentManagementSystem $cms;
    private TemplateRepository $templates;
    private ThemeManager $themes;
    private CacheManager $cache;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        AuthenticationSystem $auth,
        ContentManagementSystem $cms,
        TemplateRepository $templates,
        ThemeManager $themes,
        CacheManager $cache,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->cms = $cms;
        $this->templates = $templates;
        $this->themes = $themes;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
    }

    public function renderContent(int $contentId, string $templateId = null): RenderResult
    {
        return $this->security->executeCriticalOperation(
            new RenderOperation('content', function() use ($contentId, $templateId) {
                // Validate permissions
                $this->validatePermissions('content.view');

                // Get content and template
                $content = $this->cms->getContent($contentId);
                $template = $templateId ? 
                    $this->getTemplate($templateId) : 
                    $this->resolveDefaultTemplate($content);

                // Check cache first
                $cacheKey = "rendered_content:{$contentId}:{$template->id}";
                if ($rendered = $this->cache->get($cacheKey)) {
                    return new RenderResult($rendered);
                }

                // Render content through template
                $rendered = $this->renderSecurely([
                    'content' => $content,
                    'template' => $template,
                    'theme' => $this->themes->getCurrentTheme()
                ]);

                // Cache the result
                $this->cache->put($cacheKey, $rendered, config('cms.render_cache_ttl'));

                // Log rendering
                $this->auditLogger->logContentRender($content, $template);

                return new RenderResult($rendered);
            })
        );
    }

    public function registerTemplate(array $data): TemplateResult
    {
        return $this->security->executeCriticalOperation(
            new TemplateOperation('register', function() use ($data) {
                // Validate admin permissions
                $this->validatePermissions('templates.manage');

                // Validate template
                $validated = $this->validateTemplate($data);

                // Register template
                $template = $this->templates->create([
                    'name' => $validated['name'],
                    'path' => $validated['path'],
                    'schema' => $validated['schema'],
                    'created_by' => auth()->id()
                ]);

                // Clear template caches
                $this->cache->invalidateTemplateCaches();

                // Log template registration
                $this->auditLogger->logTemplateRegistration($template);

                return new TemplateResult($template);
            })
        );
    }

    public function compileTemplate(string $templateId): CompileResult
    {
        return $this->security->executeCriticalOperation(
            new TemplateOperation('compile', function() use ($templateId) {
                $template = $this->getTemplate($templateId);

                // Compile template to PHP
                $compiled = $this->compileSecurely($template);

                // Store compiled version
                $this->templates->storeCompiled($template->id, $compiled);

                // Update template status
                $template->update(['compiled_at' => now()]);

                // Log compilation
                $this->auditLogger->logTemplateCompilation($template);

                return new CompileResult($compiled);
            })
        );
    }

    private function renderSecurely(array $context): string
    {
        try {
            // Create isolated rendering environment
            $renderer = new SecureRenderer($this->security);

            // Prepare context with security measures
            $secureContext = $this->prepareSecureContext($context);

            // Render with security checks
            $rendered = $renderer->render(
                $context['template']->getCompiledPath(),
                $secureContext
            );

            // Validate output
            $this->validateOutput($rendered);

            return $rendered;

        } catch (\Throwable $e) {
            $this->auditLogger->logRenderingFailure($context, $e);
            throw new RenderingException('Failed to render content securely', 0, $e);
        }
    }

    private function prepareSecureContext(array $context): array
    {
        return [
            'content' => $this->sanitizeContent($context['content']),
            'theme' => $this->validateTheme($context['theme']),
            'helpers' => $this->getSecureHelpers(),
            'user' => $this->auth->getCurrentUser()
        ];
    }

    private function validateTemplate(array $data): array
    {
        $validator = validator($data, [
            'name' => 'required|string|max:255',
            'path' => 'required|string',
            'schema' => 'required|array'
        ]);

        if ($validator->fails()) {
            throw new TemplateValidationException($validator->errors()->first());
        }

        // Validate template file exists and is readable
        if (!file_exists($data['path']) || !is_readable($data['path'])) {
            throw new TemplateValidationException('Template file is not accessible');
        }

        // Validate template syntax
        $this->validateTemplateSyntax($data['path']);

        return $data;
    }

    private function validateTemplateSyntax(string $path): void
    {
        $content = file_get_contents($path);
        $ast = $this->parseTemplate($content);

        if (!$this->validateTemplateAst($ast)) {
            throw new TemplateSyntaxException('Invalid template syntax');
        }
    }

    private function compileSecurely(Template $template): string
    {
        // Parse template to AST
        $ast = $this->parseTemplate(file_get_contents($template->path));

        // Apply security transformations
        $secureAst = $this->applySecurityTransforms($ast);

        // Generate PHP code
        $php = $this->generatePhp($secureAst);

        // Validate generated code
        $this->validateGeneratedCode($php);

        return $php;
    }

    private function validateOutput(string $output): void
    {
        // Scan for potential XSS
        if ($this->security->detectXss($output)) {
            throw new SecurityException('XSS detected in rendered output');
        }

        // Validate output structure
        if (!$this->validateOutputStructure($output)) {
            throw new RenderingException('Invalid output structure');
        }
    }

    private function getSecureHelpers(): array
    {
        return [
            'escape' => fn($value) => $this->security->escapeHtml($value),
            'url' => fn($path) => $this->security->generateSecureUrl($path),
            'asset' => fn($path) => $this->security->validateAssetPath($path)
        ];
    }

    private function validatePermissions(string $permission): void
    {
        if (!$this->auth->validateSession(request()->bearerToken())->hasPermission($permission)) {
            throw new PermissionDeniedException("Missing permission: {$permission}");
        }
    }
}
