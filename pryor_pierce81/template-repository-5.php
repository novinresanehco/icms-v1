<?php

namespace App\Core\Repository;

use App\Models\Template;
use App\Core\Events\TemplateEvents;
use App\Core\Exceptions\TemplateRepositoryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TemplateRepository extends BaseRepository
{
    protected const CACHE_TIME = 3600;
    
    protected function getModelClass(): string
    {
        return Template::class;
    }

    public function createTemplate(array $data): Template
    {
        try {
            DB::beginTransaction();

            if (!isset($data['slug'])) {
                $data['slug'] = $this->generateUniqueSlug($data['name']);
            }

            $template = $this->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'content' => $data['content'],
                'type' => $data['type'] ?? 'page',
                'layout' => $data['layout'] ?? 'default',
                'status' => $data['status'] ?? 'draft',
                'metadata' => $data['metadata'] ?? null,
                'created_by' => auth()->id()
            ]);

            if (!empty($data['sections'])) {
                foreach ($data['sections'] as $section) {
                    $template->sections()->create($section);
                }
            }

            DB::commit();
            $this->clearCache();
            event(new TemplateEvents\TemplateCreated($template));

            return $template;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateRepositoryException("Failed to create template: {$e->getMessage()}");
        }
    }

    public function findBySlug(string $slug): ?Template
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("slug.{$slug}"),
            self::CACHE_TIME,
            fn() => $this->model
                ->where('slug', $slug)
                ->with(['sections', 'metadata'])
                ->first()
        );
    }

    public function getActiveTemplates(string $type = null): Collection
    {
        $cacheKey = "templates.active" . ($type ? ".{$type}" : '');

        return Cache::tags($this->getCacheTags())->remember(
            $cacheKey,
            self::CACHE_TIME,
            function() use ($type) {
                $query = $this->model->where('status', 'active');
                
                if ($type) {
                    $query->where('type', $type);
                }

                return $query->with(['sections', 'metadata'])->get();
            }
        );
    }

    public function updateTemplateContent(int $templateId, string $content): Template
    {
        try {
            DB::beginTransaction();

            $template = $this->find($templateId);
            if (!$template) {
                throw new TemplateRepositoryException("Template not found with ID: {$templateId}");
            }

            $template->update([
                'content' => $content,
                'updated_at' => now()
            ]);

            DB::commit();
            $this->clearCache();
            
            event(new TemplateEvents\TemplateContentUpdated($template));
            
            return $template;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateRepositoryException("Failed to update template content: {$e->getMessage()}");
        }
    }

    public function deleteTemplate(int $templateId): bool
    {
        try {
            DB::beginTransaction();

            $template = $this->find($templateId);
            if (!$template) {
                throw new TemplateRepositoryException("Template not found with ID: {$templateId}");
            }

            // Check if template is in use
            if ($template->contents()->exists()) {
                throw new TemplateRepositoryException("Cannot delete template that is in use");
            }

            $template->sections()->delete();
            $result = $this->delete($templateId);

            DB::commit();
            $this->clearCache();
            
            event(new TemplateEvents\TemplateDeleted($template->toArray()));
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateRepositoryException("Failed to delete template: {$e->getMessage()}");
        }
    }

    protected function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $count = 2;

        while ($this->model->where('slug', $slug)->exists()) {
            $slug = Str::slug($name) . '-' . $count;
            $count++;
        }

        return $slug;
    }

    protected function getCacheTags(): array
    {
        return ['templates'];
    }

    protected function clearCache(): void
    {
        Cache::tags($this->getCacheTags())->flush();
    }
}
