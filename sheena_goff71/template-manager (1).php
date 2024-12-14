<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{View, Cache, Event};
use App\Core\Service\BaseService;
use App\Core\Events\TemplateEvent;
use App\Core\Exceptions\{TemplateException, SecurityException};
use App\Models\Template;

class TemplateManager extends BaseService
{
    protected array $validationRules = [
        'create' => [
            'name' => 'required|string|max:255|unique:templates,name',
            'content' => 'required|string',
            'type' => 'required|in:layout,partial,email,page',
            'variables' => 'array',
            'variables.*' => 'string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'metadata' => 'nullable|array',
            'category_id' => 'nullable|exists:categories,id'
        ],
        'update' => [
            'name' => 'string|max:255|unique:templates,name',
            'content' => 'string',
            'type' => 'in:layout,partial,email,page',
            'variables' => 'array',
            'variables.*' => 'string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'metadata' => 'nullable|array',
            'category_id' => 'nullable|exists:categories,id'
        ]
    ];

    protected array $securityConfig = [
        'allowed_functions' => [
            'date', 'number_format', 'round', 'trim', 
            'strtolower', 'strtoupper', 'ucfirst', 'ucwords'
        ],
        'disallowed_patterns' => [
            '/\{\{.*\$[^}]+\}\}/',  // No direct variable output
            '/\<\?.*\?\>/',         // No PHP tags
            '/eval\s*\(/',          // No eval
            '/exec\s*\(/',          // No exec
            '/system\s*\(/',        // No system
            '/passthru\s*\(/',      // No passthru
            '/\$_[A-Z]+/'           // No superglobals
        ]
    ];

    public function create(array $data): Result
    {
        return $this->executeOperation('create', $data);
    }

    public function update(int $id, array $data): Result
    {
        $data['id'] = $id;
        return $this->executeOperation('update', $data);
    }

    public function delete(int $id): Result
    {
        return $this->executeOperation('delete', ['id' => $id]);
    }

    public function render(int $id, array $data = []): string
    {
        $template = $this->repository->findOrFail($id);
        
        // Validate template can be rendered
        $this->validateTemplateStatus($template);
        
        // Validate template data
        $this->validateTemplateData($template, $data);
        
        // Security check on data
        $this->validateDataSecurity($data);
        
        // Get cached template content
        $content = $this->getCachedContent($template);
        
        // Render template
        return $this->renderTemplate($content, $data);
    }

    protected function processOperation(string $operation, array $data, array $context): mixed
    {
        return match($operation) {
            'create' => $this->processCreate($data),
            'update' => $this->processUpdate($data),
            'delete' => $this->processDelete($data),
            default => throw new TemplateException("Invalid operation: {$operation}")
        };
    }

    protected function processCreate(array $data): Template
    {
        // Validate template security
        $this->validateTemplateSecurity($data['content']);

        // Parse template variables
        $variables = $this->parseTemplateVariables($data['content']);
        $data['variables'] = array_unique(array_merge(
            $data['variables'] ?? [],
            $variables
        ));

        // Create template
        $template = $this->repository->create($data);

        // Compile template
        $this->compileTemplate($template);

        // Fire events
        $this->events->dispatch(new TemplateEvent('created', $template));

        return $template;
    }

    protected function processUpdate(array $data): Template
    {
        $template = $this->repository->findOrFail($data['id']);

        if (isset($data['content'])) {
            // Validate template security
            $this->validateTemplateSecurity($data['content']);

            // Parse template variables
            $variables = $this->parseTemplateVariables($data['content']);
            $data['variables'] = array_unique(array_merge(
                $data['variables'] ?? [],
                $variables
            ));

            // Clear template cache
            $this->clearTemplateCache($template);
        }

        // Update template
        $updated = $this->repository->update($template, $data);

        // Recompile if content changed
        if (isset($data['content'])) {
            $this->compileTemplate($updated);
        }

        // Fire events
        $this->events->dispatch(new TemplateEvent('updated', $updated));

        return $updated;
    }

    protected function processDelete(array $data): bool
    {
        $template = $this->repository->findOrFail($data['id']);

        // Verify no dependencies
        $this->verifyNoDependencies($template);

        // Clear template cache
        $this->clearTemplateCache($template);

        // Delete template
        $deleted = $this->repository->delete($template);

        // Fire events
        $this->events->dispatch(new TemplateEvent('deleted', $template));

        return $deleted;
    }

    protected function validateTemplateSecurity(string $content): void
    {
        // Check for disallowed patterns
        foreach ($this->securityConfig['disallowed_patterns'] as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new SecurityException('Template contains disallowed pattern');
            }
        }

        // Validate function calls
        preg_match_all('/\{\{.*?\}\}/', $content, $matches);
        foreach ($matches[0] as $match) {
            $this->validateFunctionCall($match);
        }
    }

    protected function validateFunctionCall(string $expression): void
    {
        preg_match_all('/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\s*\(/', $expression, $matches);
        
        foreach ($matches[0] as $function) {
            $function = trim($function, '(');
            if (!in_array($function, $this->securityConfig['allowed_functions'])) {
                throw new SecurityException("Function not allowed: {$function}");
            }
        }
    }

    protected function validateTemplateStatus(Template $template): void
    {
        if ($template->status !== 'active') {
            throw new TemplateException('Template is not active');
        }
    }

    protected function validateTemplateData(Template $template, array $data): void
    {
        $missing = array_diff($template->variables, array_keys($data));
        if (!empty($missing)) {
            throw new TemplateException('Missing required variables: ' . implode(', ', $missing));
        }
    }

    protected function validateDataSecurity(array $data): void
    {
        array_walk_recursive($data, function($value) {
            if (is_string($value)) {
                $this->validateStringData($value);
            }
        });
    }

    protected function validateStringData(string $value): void
    {
        foreach ($this->securityConfig['disallowed_patterns'] as $pattern) {
            if (preg_match($pattern, $value)) {
                throw new SecurityException('Data contains disallowed pattern');
            }
        }
    }

    protected function parseTemplateVariables(string $content): array
    {
        preg_match_all('/\{\{\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $content, $matches);
        return $matches[1] ?? [];
    }

    protected function compileTemplate(Template $template): void
    {
        $compiled = View::compileString($template->content);
        
        Cache::tags(['templates', "template.{$template->id}"])
            ->put(
                $this->getTemplateCacheKey($template),
                $compiled,
                now()->addDays(30)
            );
    }

    protected function getCachedContent(Template $template): string
    {
        $key = $this->getTemplateCacheKey($template);
        
        if (!Cache::tags(['templates', "template.{$template->id}"])->has($key)) {
            $this->compileTemplate($template);
        }

        return Cache::tags(['templates', "template.{$template->id}"])->get($key);
    }

    protected function clearTemplateCache(Template $template): void
    {
        Cache::tags(['templates', "template.{$template->id}"])->flush();
    }

    protected function getTemplateCacheKey(Template $template): string
    {
        return "template.{$template->id}.compiled";
    }

    protected function renderTemplate(string $compiled, array $data): string
    {
        return View::make('template', [
            'template' => $compiled,
            'data' => $data
        ])->render();
    }

    protected function verifyNoDependencies(Template $template): void
    {
        if ($this->hasDependencies($template)) {
            throw new TemplateException('Template has active dependencies');
        }
    }

    protected function hasDependencies(Template $template): bool
    {
        // Implementation of dependency checking
        return false;
    }

    protected function getValidationRules(string $operation): array
    {
        return $this->validationRules[$operation] ?? [];
    }

    protected function getRequiredPermissions(string $operation): array
    {
        return ["templates.{$operation}"];
    }

    protected function isValidResult(string $operation, $result): bool
    {
        return match($operation) {
            'create', 'update' => $result instanceof Template,
            'delete' => is_bool($result),
            default => false
        };
    }
}
