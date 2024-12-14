<?php

namespace App\Repositories;

use App\Models\Template;
use App\Repositories\Contracts\TemplateRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class TemplateRepository extends BaseRepository implements TemplateRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'content'];
    protected array $filterableFields = ['type', 'status', 'category_id'];

    public function getActiveTemplates(string $type = null): Collection
    {
        $cacheKey = 'templates.active' . ($type ? '.' . $type : '');

        return Cache::tags(['templates'])->remember($cacheKey, 3600, function() use ($type) {
            $query = $this->model->where('status', 'active');

            if ($type) {
                $query->where('type', $type);
            }

            return $query->orderBy('name')->get();
        });
    }

    public function findBySlug(string $slug, array $relations = []): ?Template
    {
        $cacheKey = 'templates.slug.' . $slug . '.' . md5(serialize($relations));

        return Cache::tags(['templates'])->remember($cacheKey, 3600, function() use ($slug, $relations) {
            return $this->model
                ->where('slug', $slug)
                ->with($relations)
                ->first();
        });
    }

    public function createVersion(Template $template, array $data = []): bool
    {
        try {
            $template->versions()->create([
                'content' => $data['content'] ?? $template->content,
                'metadata' => [
                    'editor_id' => auth()->id(),
                    'editor_ip' => request()->ip(),
                    'changes' => $this->calculateChanges($template, $data)
                ]
            ]);

            Cache::tags(['templates'])->flush();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error creating template version: ' . $e->getMessage());
            return false;
        }
    }

    public function getVersions(int $templateId): Collection
    {
        return $this->find($templateId)
            ->versions()
            ->orderByDesc('created_at')
            ->get();
    }

    public function duplicate(int $id): ?Template
    {
        $template = $this->find($id);

        if (!$template) {
            return null;
        }

        $duplicate = $this->create([
            'name' => $template->name . ' (copy)',
            'slug' => $template->slug . '-copy',
            'description' => $template->description,
            'content' => $template->content,
            'type' => $template->type,
            'category_id' => $template->category_id,
            'metadata' => array_merge(
                $template->metadata ?? [],
                ['duplicated_from' => $template->id]
            )
        ]);

        Cache::tags(['templates'])->flush();

        return $duplicate;
    }

    public function updateContent(int $id, string $content): Template
    {
        $template = $this->find($id);
        
        // Create version before updating
        $this->createVersion($template);
        
        $template = $this->update($id, ['content' => $content]);
        
        Cache::tags(['templates'])->flush();
        
        return $template;
    }

    protected function calculateChanges(Template $template, array $newData): array
    {
        $changes = [];
        
        foreach ($newData as $field => $value) {
            if ($template->$field !== $value) {
                $changes[$field] = [
                    'old' => $template->$field,
                    'new' => $value
                ];
            }
        }
        
        return $changes;
    }
}
