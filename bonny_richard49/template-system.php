<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Auth\AuthenticationManager;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\File;

class TemplateManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private AuthenticationManager $auth;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        AuthenticationManager $auth,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->auth = $auth;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function renderTemplate(string $templateId, array $data, ?User $user = null): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processTemplateRendering($templateId, $data, $user),
            new SecurityContext('template_render', [
                'template_id' => $templateId,
                'user' => $user
            ])
        );
    }

    private function processTemplateRendering(string $templateId, array $data, ?User $user): string
    {
        $template = $this->loadTemplate($templateId);
        
        // Verify template access
        if (!$this->canAccessTemplate($template, $user)) {
            throw new UnauthorizedException('Template access denied');
        }

        // Validate template data
        $this->validateTemplateData($template, $data);

        // Process template components
        $processedData = $this->processComponents($template, $data);

        // Render with security context
        return $this->renderSecurely($template, $processedData);
    }

    private function loadTemplate(string $templateId): Template
    {
        return $this->cache->remember(
            "template:{$templateId}",
            3600,
            fn() => Template::findOrFail($templateId)
        );
    }

    private function canAccessTemplate(Template $template, ?User $user): bool
    {
        if ($template->isPublic()) {
            return true;
        }

        return $user && $user->can('view', $template);
    }

    private function validateTemplateData(Template $template, array $data): void
    {
        $rules = $template->getValidationRules();
        
        if (!$this->validator->validate($data, $rules)) {
            throw new TemplateException('Invalid template data');
        }
    }

    private function processComponents(Template $template, array $data): array
    {
        $processed = [];
        
        foreach ($template->getComponents() as $component) {
            $processed[$component->getName()] = $this->renderComponent(
                $component,
                $data[$component->getName()] ?? []
            );
        }

        return $processed;
    }

    private function renderComponent(Component $component, array $data): string
    {
        // Verify component security
        $this->verifyComponentSecurity($component);

        // Process component with sandbox
        return $this->componentSandbox->render($component, $data);
    }

    private function verifyComponentSecurity(Component $component): void
    {
        // Verify component signature
        if (!$this->verifyComponentSignature($component)) {
            throw new SecurityException('Invalid component signature');
        }

        // Check for blacklisted functions
        if ($this->containsBlacklistedFunctions($component)) {
            throw new SecurityException('Component contains forbidden functions');
        }
    }

    private function renderSecurely(Template $template, array $data): string
    {
        try {
            // Create secure rendering environment
            $environment = $this->createSecureEnvironment();

            // Render template
            $rendered = $environment->render($template, $data);

            // Post-render security checks
            $this->postRenderSecurityCheck($rendered);

            return $rendered;

        } catch (\Throwable $e) {
            $this->auditLogger->logRenderingFailure($template, $e);
            throw new TemplateException('Template rendering failed: ' . $e->getMessage());
        }
    }

    private function createSecureEnvironment(): TemplateEnvironment
    {
        return new TemplateEnvironment([
            'auto_escape' => true,
            'sandbox' => true,
            'allowed_functions' => $this->getAllowedFunctions(),
            'cache' => $this->cache
        ]);
    }

    private function postRenderSecurityCheck(string $rendered): void
    {
        // Check for XSS attempts
        if ($this->containsXSSPatterns($rendered)) {
            throw new SecurityException('Potential XSS detected in rendered output');
        }

        // Validate output structure
        if (!$this->validator->validateOutput($rendered)) {
            throw new ValidationException('Invalid template output');
        }
    }

    public function createTemplate(array $data, User $user): Template
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processTemplateCreation($data, $user),
            new SecurityContext('template_creation', [
                'user' => $user,
                'data' => $data
            ])
        );
    }

    private function processTemplateCreation(array $data, User $user): Template
    {
        // Validate template structure
        $this->validateTemplateStructure($data);

        // Create template
        $template = new Template([
            'name' => $data['name'],
            'type' => $data['type'],
            'content' => $this->sanitizeTemplateContent($data['content']),
            'user_id' => $user->id,
            'is_public' => $data['is_public'] ?? false,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $template->save();

        // Process components
        if (!empty($data['components'])) {
            $this->processTemplateComponents($template, $data['components']);
        }

        // Log template creation
        $this->auditLogger->logTemplateCreation($template, $user);

        // Invalidate relevant caches
        $this->invalidateTemplateCaches($template);

        return $template->fresh(['components']);
    }

    private function validateTemplateStructure(array $data): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:page,partial,layout',
            'content' => 'required|string',
            'components' => 'array',
            'components.*.name' => 'required|string',
            'components.*.type' => 'required|string'
        ];

        if (!$this->validator->validate($data, $rules)) {
            throw new TemplateException('Invalid template structure');
        }
    }

    private function sanitizeTemplateContent(string $content): string
    {
        // Remove potentially harmful content
        return clean($content);
    }

    private function processTemplateComponents(Template $template, array $components): void
    {
        foreach ($components as $componentData) {
            $component = new Component([
                'template_id' => $template->id,
                'name' => $componentData['name'],
                'type' => $componentData['type'],
                'content' => $this->sanitizeTemplateContent($componentData['content'] ?? ''),
                'created_at' => now()
            ]);

            $component->save();
        }
    }

    private function invalidateTemplateCaches(Template $template): void
    {
        $this->cache->tags(['templates', "template:{$template->id}"])->flush();
    }
}
