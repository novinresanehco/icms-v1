<?php

namespace App\Repositories;

use App\Models\Template;
use App\Repositories\Contracts\TemplateRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TemplateRepository extends BaseRepository implements TemplateRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'path'];
    protected array $filterableFields = ['type', 'status'];

    public function getActiveTemplates(): Collection
    {
        return Cache::tags(['templates'])->remember('templates.active', 3600, function() {
            return $this->model
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
        });
    }

    public function findByPath(string $path): ?Template
    {
        return $this->model
            ->where('path', $path)
            ->first();
    }

    public function compileTemplate(Template $template, array $data): string
    {
        try {
            $compiler = app('App\Services\Template\TemplateCompiler');
            return $compiler->compile($template, $data);
        } catch (\Exception $e) {
            \Log::error('Template compilation error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateTemplateContent(int $id, string $content): bool
    {
        try {
            $template = $this->findById($id);
            $template->content = $content;
            $template->last_modified = now();
            $template->save();
            
            Cache::tags(['templates'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating template content: ' . $e->getMessage());
            return false;
        }
    }

    public function getTemplateVariables(Template $template): array
    {
        try {
            preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template->content, $matches);
            return array_unique($matches[1]);
        } catch (\Exception $e) {
            \Log::error('Error extracting template variables: ' . $e->getMessage());
            return [];
        }
    }
}
