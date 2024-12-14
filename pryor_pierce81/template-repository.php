<?php

namespace App\Core\Repository;

use App\Models\Template;
use App\Core\Events\TemplateEvents;
use App\Core\Exceptions\TemplateRepositoryException;
use Illuminate\Support\Facades\Storage;

class TemplateRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Template::class;
    }

    /**
     * Get active template by key
     */
    public function getActiveTemplate(string $key): ?Template
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('active', $key),
            $this->cacheTime,
            fn() => $this->model->where('key', $key)
                               ->where('status', 'active')
                               ->first()
        );
    }

    /**
     * Create template with content
     */
    public function createTemplate(array $data, string $content): Template
    {
        try {
            DB::beginTransaction();

            // Create template record
            $template = $this->create($data);

            // Store template content
            Storage::disk('templates')->put(
                $template->id . '.blade.php',
                $content
            );

            DB::commit();
            event(new TemplateEvents\TemplateCreated($template));

            return $template;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateRepositoryException(
                "Failed to create template: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update template content
     */
    public function updateContent(int $id, string $content): Template
    {
        try {
            $template = $this->find($id);
            if (!$template) {
                throw new TemplateRepositoryException("Template not found with ID: {$id}");
            }

            // Update template content
            Storage::disk('templates')->put(
                $template->id . '.blade.php',
                $content
            );

            $this->clearCache();
            event(new TemplateEvents\TemplateContentUpdated($template));

            return $template;

        } catch (\Exception $e) {
            throw new TemplateRepositoryException(
                "Failed to update template content: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get template types
     */
    public function getTemplateTypes(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('types'),
            $this->cacheTime,
            fn() => $this->model->select('type')
                               ->distinct()
                               ->pluck('type')
        );
    }

    /**
     * Get templates by type
     */
    public function getByType(string $type): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('byType', $type),
            $this->cacheTime,
            fn() => $this->model->where('type', $type)
                               ->orderBy('name')
                               ->get()
        );
    }
}
