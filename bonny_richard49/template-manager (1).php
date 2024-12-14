<?php

namespace App\Core\Template;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\TemplateException;

class TemplateManager
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function createTemplate(array $data): Template
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCreate($data),
            ['operation' => 'template_create', 'data' => $data]
        );
    }

    public function updateTemplate(int $id, array $data): Template
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpdate($id, $data),
            ['operation' => 'template_update', 'id' => $id, 'data' => $data]
        );
    }

    public function renderTemplate(int $id, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRender($id, $data),
            ['operation' => 'template_render', 'id' => $id]
        );
    }

    private function executeCreate(array $data): Template
    {
        $this->validateTemplateData($data);

        try {
            // Compile template
            $compiled = $this->compileTemplate($data['content']);
            
            // Create template version
            $template = Template::create([
                'name' => $data['name'],
                'content' => $data['content'],
                'compiled' => $compiled,
                'variables' => $this->extractVariables($data['content']),
                'type' => $data['type'],
                'status' => 'active'
            ]);

            // Create initial version
            $this->createTemplateVersion($template);

            return $template;

        } catch (\Exception $e) {
            throw new TemplateException('Failed to create template: ' . $e->getMessage());
        }
    }

    private function executeUpdate(int $id, array $data): Template
    {
        $this->validateTemplateData($data);

        try {
            $template = Template::findOrFail($id);
            
            // Compile new content
            if (isset($data['content'])) {
                $data['compiled'] = $this->compileTemplate($data['content']);
                $data['variables'] = $this->extractVariables($data['content']);
            }

            // Update template
            $template->update($data);

            // Create new version
            $this->createTemplateVersion($template);

            // Clear cache
            $this->clearTemplateCache($id);

            return $template;

        } catch (\Exception $e) {
            throw new TemplateException('Failed to update template: ' . $e->getMessage());
        }
    }

    private function executeRender(int $id, array $data): string
    {
        try {
            // Try to get from cache
            $cacheKey = $this->getTemplateCacheKey($id, $data);
            
            return Cache::remember($cacheKey, $this->config['cache_ttl'], function() use ($id, $data) {
                $template = Template::findOrFail($id);
                
                // Validate template status
                if ($template->status !== 'active') {
                    throw new TemplateException('Template is not active');
                }

                // Validate provided data
                $this->validateTemplateVariables($template, $data);
                
                // Render template
                return $this->renderCompiledTemplate($template->compiled, $data);
            });

        } catch (\Exception $e) {
            throw new TemplateException('Failed to render template: ' . $e->getMessage());
        }
    }

    private function validateTemplateData(array $data): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'required|string|in:html,text,email',
            'status' => 'sometimes|string|in:active,inactive'
        ];

        if (!$this->validator->validateData($data, $rules)) {
            throw new TemplateException('Invalid template data');
        }

        // Validate template syntax
        if (isset($data['content'])) {
            $this->validateTemplateSyntax($data['content']);
        }
    }

    private function validateTemplateSyntax(string $content): void
    {
        try {
            $this->compileTemplate($content);
        } catch (\Exception $e) {
            throw new TemplateException('Invalid template syntax: ' . $e->getMessage());
        }
    }

    private function validateTemplateVariables(Template $template, array $data): void
    {
        $required = $template->variables;

        foreach ($required as $variable) {
            if (!isset($data[$variable])) {
                throw new TemplateException("Missing required variable: {$variable}");
            }
        }
    }

    private function compileTemplate(string $content): string
    {
        // Remove any unsafe constructs
        $content = $this->sanitizeTemplate($content);
        
        // Compile template
        return $this->compileTemplateContent($content);
    }

    private function sanitizeTemplate(string $content): string
    {
        // Remove PHP tags
        $content = preg_replace('/<\?(?:php|=)?.*?\?>/i', '', $content);
        
        // Remove potentially dangerous constructs
        $content = preg_replace('/\{\{.*?(?:eval|exec|system|passthru).*?\}\}/i', '', $content);
        
        return $content;
    }

    private function compileTemplateContent(string $content): string
    {
        // Replace variables
        $content = preg_replace('/\{\{\s*(\$?\w+)\s*\}\}/', '<?php echo $this->e($1); ?>', $content);
        
        // Replace conditionals
        $content = preg_replace(
            '/\@if\((.*?)\)/',
            '<?php if($1): ?>',
            $content
        );
        
        $content = str_replace('@endif', '<?php endif; ?>', $content);
        
        return $content;
    }

    private function extractVariables(string $content): array
    {
        preg_match_all('/\{\{\s*(\$?\w+)\s*\}\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    private function renderCompiledTemplate(string $compiled, array $data): string
    {
        $renderer = new TemplateRenderer($data);
        return $renderer->render($compiled);
    }

    private function createTemplateVersion(Template $template): void
    {
        TemplateVersion::create([
            'template_id' => $template->id,
            'content' => $template->content,
            'compiled' => $template->compiled,
            'variables' => $template->variables,
            'created_by' => auth()->id()
        ]);
    }

    private function getTemplateCacheKey(int $id, array $data): string
    {
        return sprintf(
            'template:%d:%s',
            $id,
            md5(serialize($data))
        );
    }

    private function clearTemplateCache(int $id): void
    {
        Cache::tags(['templates'])->forget("template:{$id}");
    }
}

class TemplateRenderer
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function render(string $compiled): string
    {
        ob_start();
        
        extract($this->data);
        
        try {
            eval('?>' . $compiled);
        } catch (\Exception $e) {
            ob_end_clean();
            throw new TemplateException('Failed to render template: ' . $e->getMessage());
        }

        return ob_get_clean();
    }

    public function e($value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}