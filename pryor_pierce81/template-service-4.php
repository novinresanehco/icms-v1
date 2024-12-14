<?php

namespace App\Core\Services;

use App\Core\Repository\TemplateRepository;
use App\Core\Validation\TemplateValidator;
use App\Core\Rendering\TemplateRenderer;
use App\Core\Events\TemplateEvents;
use App\Core\Exceptions\TemplateServiceException;
use App\Models\Template;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TemplateService
{
    public function __construct(
        protected TemplateRepository $repository,
        protected TemplateValidator $validator,
        protected TemplateRenderer $renderer
    ) {}

    public function createTemplate(array $data): Template
    {
        $this->validator->validateCreation($data);

        try {
            if ($this->repository->findBySlug($data['slug'] ?? Str::slug($data['name']))) {
                throw new TemplateServiceException("Template with this slug already exists");
            }

            if (!empty($data['content'])) {
                $this->validator->validateSyntax($data['content']);
            }

            return $this->repository->createTemplate($data);

        } catch (\Exception $e) {
            throw new TemplateServiceException("Failed to create template: {$e->getMessage()}", 0, $e);
        }
    }

    public function updateTemplate(int $templateId, array $data): Template
    {
        $this->validator->validateUpdate($data);

        try {
            DB::beginTransaction();

            $template = $this->repository->find($templateId);
            if (!$template) {
                throw new TemplateServiceException("Template not found with ID: {$templateId}");
            }

            if (!empty($data['content'])) {
                $this->validator->validateSyntax($data['content']);
            }

            $template = $this->repository->update($templateId, $data);
            
            DB::commit();
            return $template;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateServiceException("Failed to update template: {$e->getMessage()}", 0, $e);
        }
    }

    public function renderTemplate(Template $template, array $data = []): string
    {
        try {
            return $this->renderer->render($template, $data);
        } catch (\Exception $e) {
            throw new TemplateServiceException("Failed to render template: {$e->getMessage()}", 0, $e);
        }
    }

    public function compileTemplate(Template $template): string
    {
        try {
            return $this->renderer->compile($template);
        } catch (\Exception $e) {
            throw new TemplateServiceException("Failed to compile template: {$e->getMessage()}", 0, $e);
        }
    }

    public function duplicateTemplate(int $templateId, ?string $newName = null): Template
    {
        try {
            DB::beginTransaction();

            $template = $this->repository->find($templateId);
            if (!$template) {
                throw new TemplateServiceException("Template not found with ID: {$templateId}");
            }

            $data = $template->toArray();
            $data['name'] = $newName ?? $data['name'] . ' (Copy)';
            unset($data['id'], $data['created_at'], $data['updated_at']);

            $newTemplate = $this->repository->createTemplate($data);

            foreach ($template->sections as $section) {
                $sectionData = $section->toArray();
                unset($sectionData['id'], $sectionData['template_id']);
                $newTemplate->sections()->create($sectionData);
            }

            DB::commit();
            return $newTemplate;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateServiceException("Failed to duplicate template: {$e->getMessage()}", 0, $e);
        }
    }

    public function exportTemplate(int $templateId): array
    {
        try {
            $template = $this->repository->find($templateId);
            if (!$template) {
                throw new TemplateServiceException("Template not found with ID: {$templateId}");
            }

            return [
                'name' => $template->name,
                'description' => $template->description,
                'content' => $template->content,
                'type' => $template->type,
                'layout' => $template->layout,
                'metadata' => $template->metadata,
                'sections' => $template->sections->map(function ($section) {
                    return [
                        'name' => $section->name,
                        'content' => $section->content,
                        'order' => $section->order,
                        'settings' => $section->settings
                    ];
                })->toArray()
            ];

        } catch (\Exception $e) {
            throw new TemplateServiceException("Failed to export template: {$e->getMessage()}", 0, $e);
        }
    }

    public function importTemplate(array $data): Template
    {
        $this->validator->validateImport($data);

        try {
            return $this->repository->createTemplate($data);
        } catch (\Exception $e) {
            throw new TemplateServiceException("Failed to import template: {$e->getMessage()}", 0, $e);
        }
    }
}
