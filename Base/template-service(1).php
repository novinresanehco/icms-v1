<?php

namespace App\Core\Services;

use App\Core\Models\Template;
use App\Core\Repositories\Contracts\TemplateRepositoryInterface;
use App\Core\Services\Contracts\TemplateServiceInterface;
use App\Core\Events\TemplateCreated;
use App\Core\Events\TemplateUpdated;
use App\Core\Events\TemplateDeleted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class TemplateService implements TemplateServiceInterface
{
    protected TemplateRepositoryInterface $repository;

    public function __construct(TemplateRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function createTemplate(array $data): Template
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $template = $this->repository->create($data);
        Event::dispatch(new TemplateCreated($template));
        return $template;
    }

    public function updateTemplate(Template $template, array $data): bool
    {
        if (isset($data['name']) && !isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }
        
        $updated = $this->repository->update($template, $data);
        
        if ($updated) {
            Event::dispatch(new TemplateUpdated($template));
        }
        
        return $updated;
    }

    public function deleteTemplate(Template $template): bool
    {
        $deleted = $this->repository->delete($template);
        
        if ($deleted) {
            Event::dispatch(new TemplateDeleted($template));
        }
        
        return $deleted;
    }

    public function compileTemplate(Template $template, array $variables = []): string
    {
        return view()
            ->make("templates.{$template->type}.{$template->slug}", $variables)
            ->render();
    }

    public function validateTemplate(string $content): bool
    {
        try {
            view()->make('template-validator', ['content' => $content])->render();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
