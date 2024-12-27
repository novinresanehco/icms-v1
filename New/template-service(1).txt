<?php

namespace App\Core\Services;

use App\Core\Repositories\TemplateRepository;
use App\Core\Security\{AccessControlService, AuditService};
use App\Exceptions\TemplateException;
use App\Models\Template;
use Illuminate\Support\Facades\{Cache, View};
use Illuminate\Contracts\View\Factory as ViewFactory;

class TemplateService
{
    protected TemplateRepository $repository;
    protected AccessControlService $accessControl;
    protected AuditService $auditService;
    protected ViewFactory $viewFactory;
    protected array $compiledTemplates = [];

    public function __construct(
        TemplateRepository $repository,
        AccessControlService $accessControl,
        AuditService $auditService,
        ViewFactory $viewFactory
    ) {
        $this->repository = $repository;
        $this->accessControl = $accessControl;
        $this->auditService = $auditService;
        $this->viewFactory = $viewFactory;
    }

    public function render(string $templateName, array $data = []): string
    {
        $template = $this->getTemplate($templateName);
        
        if (!$template) {
            throw new TemplateException('Failed to compile template: ' . $e->getMessage());
        }
    }

    protected function renderCompiled(Template $template, array $data): string
    {
        $compiled = $this->compiledTemplates[$template->id];
        return $this->viewFactory->make($compiled, $data)->render();
    }

    protected function clearTemplateCache(Template $template): void
    {
        Cache::forget("template:{$template->name}");
        unset($this->compiledTemplates[$template->id]);
    }

    public function list(array $filters = []): array
    {
        return $this->repository->list($filters);
    }

    public function clone(int $id, string $newName): Template
    {
        $template = $this->repository->find($id);
        
        if (!$template) {
            throw new TemplateException('Template not found');
        }

        $data = $template->toArray();
        $data['name'] = $newName;
        $data['is_system'] = false;

        return $this->create($data);
    }
}ateException("Template not found: {$templateName}");
        }

        $this->validateTemplate($template);

        return $this->renderTemplate($template, $data);
    }

    public function create(array $data): Template
    {
        $this->validateTemplateData($data);

        $template = $this->repository->create($data);

        $this->auditService->logSecurityEvent('template_created', [
            'template_id' => $template->id,
            'name' => $template->name
        ]);

        $this->clearTemplateCache($template);

        return $template;
    }

    public function update(int $id, array $data): Template
    {
        $template = $this->repository->find($id);
        
        if (!$template) {
            throw new TemplateException('Template not found');
        }

        $this->validateTemplateData($data);

        $template = $this->repository->update($id, $data);

        $this->auditService->logSecurityEvent('template_updated', [
            'template_id' => $template->id,
            'name' => $template->name
        ]);

        $this->clearTemplateCache($template);

        return $template;
    }

    public function delete(int $id): bool
    {
        $template = $this->repository->find($id);
        
        if (!$template) {
            throw new TemplateException('Template not found');
        }

        if ($template->is_system) {
            throw new TemplateException('Cannot delete system template');
        }

        $result = $this->repository->delete($id);

        if ($result) {
            $this->auditService->logSecurityEvent('template_deleted', [
                'template_id' => $id,
                'name' => $template->name
            ]);
            
            $this->clearTemplateCache($template);
        }

        return $result;
    }

    protected function getTemplate(string $name): ?Template
    {
        return Cache::remember(
            "template:{$name}",
            3600,
            fn() => $this->repository->findByName($name)
        );
    }

    protected function validateTemplate(Template $template): void
    {
        if (!$template->is_active) {
            throw new TemplateException('Template is not active');
        }

        if ($template->requires_compilation && !isset($this->compiledTemplates[$template->id])) {
            $this->compiledTemplates[$template->id] = $this->compileTemplate($template);
        }
    }

    protected function validateTemplateData(array $data): void
    {
        if (empty($data['name'])) {
            throw new TemplateException('Template name is required');
        }

        if (empty($data['content'])) {
            throw new TemplateException('Template content is required');
        }

        // Validate template syntax
        try {
            $this->viewFactory->make($data['content']);
        } catch (\Exception $e) {
            throw new TemplateException('Invalid template syntax: ' . $e->getMessage());
        }
    }

    protected function renderTemplate(Template $template, array $data): string
    {
        try {
            if ($template->requires_compilation) {
                return $this->renderCompiled($template, $data);
            }

            return $this->viewFactory->make($template->content, $data)->render();
        } catch (\Exception $e) {
            throw new TemplateException('Failed to render template: ' . $e->getMessage());
        }
    }

    protected function compileTemplate(Template $template): string
    {
        try {
            return $this->viewFactory->make($template->content)->render();
        } catch (\Exception $e) {
            throw new Templ