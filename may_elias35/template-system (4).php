<?php
namespace App\Core\Template;

use App\Core\Security\{SecurityManager, ValidationService};
use App\Core\Exceptions\{TemplateException, RenderException};
use Illuminate\Support\Facades\{Cache, View};

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private TemplateRepository $repository;
    private TemplateCompiler $compiler;
    private AuditLogger $audit;

    public function renderTemplate(string $identifier, array $data, SecurityContext $context): string 
    {
        return $this->security->executeCriticalOperation(function() use ($identifier, $data, $context) {
            $template = $this->loadTemplate($identifier);
            $validated = $this->validateTemplateData($data, $template);
            
            try {
                $compiled = $this->getCachedTemplate($template);
                $rendered = $this->renderContent($compiled, $validated);
                
                $this->validateOutput($rendered, $template);
                $this->audit->logTemplateRender($template, $context);
                
                return $rendered;
            } catch (\Throwable $e) {
                $this->handleRenderFailure($e, $template, $context);
                throw new RenderException(
                    'Template render failed: ' . $e->getMessage(),
                    previous: $e
                );
            }
        }, $context);
    }

    public function compileTemplate(Template $template, SecurityContext $context): CompiledTemplate 
    {
        return $this->security->executeCriticalOperation(function() use ($template, $context) {
            $this->validateTemplate($template);
            
            return DB::transaction(function() use ($template, $context) {
                $compiled = $this->compiler->compile($template);
                
                $this->validateCompiledTemplate($compiled);
                $this->storeCompiledTemplate($compiled);
                
                $this->audit->logTemplateCompilation($template, $context);
                return $compiled;
            });
        }, $context);
    }

    public function validateTemplate(Template $template): void 
    {
        if (!$this->validator->validateTemplate($template)) {
            throw new TemplateException('Invalid template structure');
        }

        if ($this->containsUnsafeContent($template)) {
            throw new SecurityException('Template contains unsafe content');
        }

        foreach ($template->getIncludes() as $include) {
            $this->validateTemplateInclude($include);
        }
    }

    private function loadTemplate(string $identifier): Template 
    {
        $template = $this->repository->find($identifier);
        
        if (!$template) {
            throw new TemplateException("Template not found: {$identifier}");
        }
        
        return $template;
    }

    private function getCachedTemplate(Template $template): CompiledTemplate 
    {
        return Cache::tags(['templates', $template->identifier])
            ->remember(
                $this->getCacheKey($template),
                config('templates.cache_ttl'),
                fn() => $this->compileTemplate($template, new SecurityContext())
            );
    }

    private function validateTemplateData(array $data, Template $template): array 
    {
        $schema = $template->getValidationSchema();
        return $this->validator->validateData($data, $schema);
    }

    private function renderContent(CompiledTemplate $compiled, array $data): string 
    {
        return View::make($compiled->getView(), $data)->render();
    }

    private function validateOutput(string $output, Template $template): void 
    {
        if (!$this->validator->validateOutput($output)) {
            throw new RenderException('Template output validation failed');
        }

        if (!$this->validateSecurityConstraints($output)) {
            throw new SecurityException('Template output security validation failed');
        }
    }

    private function validateTemplateInclude(TemplateInclude $include): void 
    {
        if (!$this->validator->validateInclude($include)) {
            throw new TemplateException("Invalid template include: {$include->identifier}");
        }

        if (!$this->security->checkIncludeAccess($include)) {
            throw new SecurityException("Unauthorized template include: {$include->identifier}");
        }
    }

    private function containsUnsafeContent(Template $template): bool 
    {
        return $this->validator->containsUnsafePatterns($template->content) ||
               $this->validator->containsUnsafeIncludes($template->getIncludes());
    }

    private function validateSecurityConstraints(string $output): bool 
    {
        return !$this->validator->containsXSS($output) &&
               !$this->validator->containsUnsafeUrls($output) &&
               $this->validator->validateResourceReferences($output);
    }

    private function handleRenderFailure(\Throwable $e, Template $template, SecurityContext $context): void 
    {
        $this->audit->logRenderFailure($e, $template, $context);
        Cache::tags(['templates', $template->identifier])->flush();
        
        if ($this->isSecurityRelated($e)) {
            $this->security->handleSecurityEvent($e, $context);
        }
    }

    private function getCacheKey(Template $template): string 
    {
        return sprintf(
            'template:%s:%s',
            $template->identifier,
            $template->getVersion()
        );
    }
}
