<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\TemplateRepositoryInterface;
use App\Core\Models\Template;
use App\Core\Exceptions\TemplateRepositoryException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{Cache, DB, File, View};
use Illuminate\Support\Str;

class TemplateRepository implements TemplateRepositoryInterface
{
    protected Template $model;
    protected const CACHE_PREFIX = 'template:';
    protected const CACHE_TTL = 3600;

    public function __construct(Template $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Model
    {
        try {
            DB::beginTransaction();

            $template = $this->model->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'content' => $data['content'],
                'type' => $data['type'] ?? 'blade',
                'category' => $data['category'] ?? 'general',
                'status' => $data['status'] ?? 'draft',
                'author_id' => auth()->id(),
                'variables' => $data['variables'] ?? [],
                'settings' => $data['settings'] ?? [],
                'version' => '1.0.0'
            ]);

            if (!empty($data['regions'])) {
                foreach ($data['regions'] as $region) {
                    $template->regions()->create($region);
                }
            }

            DB::commit();
            $this->clearCache();
            $this->compileTemplate($template);

            return $template->load('regions');
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateRepositoryException("Failed to create template: {$e->getMessage()}", 0, $e);
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            DB::beginTransaction();

            $template = $this->findById($id);
            
            $template->update([
                'name' => $data['name'] ?? $template->name,
                'slug' => $data['slug'] ?? $template->slug,
                'description' => $data['description'] ?? $template->description,
                'content' => $data['content'] ?? $template->content,
                'type' => $data['type'] ?? $template->type,
                'category' => $data['category'] ?? $template->category,
                'status' => $data['status'] ?? $template->status,
                'variables' => array_merge($template->variables, $data['variables'] ?? []),
                'settings' => array_merge($template->settings, $data['settings'] ?? []),
                'version' => $this->incrementVersion($template->version)
            ]);

            if (isset($data['regions'])) {
                $template->regions()->delete();
                foreach ($data['regions'] as $region) {
                    $template->regions()->create($region);
                }
            }

            DB::commit();
            $this->clearCache();
            $this->compileTemplate($template);

            return $template->load('regions');
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateRepositoryException("Failed to update template: {$e->getMessage()}", 0, $e);
        }
    }

    public function findById(int $id): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with(['regions', 'author'])->findOrFail($id)
        );
    }

    public function findBySlug(string $slug): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn () => $this->model->with(['regions', 'author'])
                ->where('slug', $slug)
                ->firstOrFail()
        );
    }

    public function findByType(string $type): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "type:{$type}",
            self::CACHE_TTL,
            fn () => $this->model->where('type', $type)
                ->with(['regions'])
                ->get()
        );
    }

    public function getActiveTemplates(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'active',
            self::CACHE_TTL,
            fn () => $this->model->where('status', 'active')
                ->with(['regions'])
                ->get()
        );
    }

    public function compile(int $id, array $data = []): string
    {
        try {
            $template = $this->findById($id);
            return View::make("templates.{$template->slug}", $data)->render();
        } catch (\Exception $e) {
            throw new TemplateRepositoryException("Failed to compile template: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $template = $this->findById($id);
            $template->regions()->delete();
            $deleted = $template->delete();

            // Remove compiled template
            $this->removeCompiledTemplate($template);

            DB::commit();
            $this->clearCache();

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateRepositoryException("Failed to delete template: {$e->getMessage()}", 0, $e);
        }
    }

    public function duplicate(int $id, array $data = []): Model
    {
        try {
            DB::beginTransaction();

            $source = $this->findById($id);
            
            $duplicate = $this->model->create([
                'name' => $data['name'] ?? $source->name . ' (Copy)',
                'slug' => $data['slug'] ?? Str::slug($data['name'] ?? $source->name . '-copy'),
                'description' => $source->description,
                'content' => $source->content,
                'type' => $source->type,
                'category' => $source->category,
                'status' => 'draft',
                'author_id' => auth()->id(),
                'variables' => $source->variables,
                'settings' => $source->settings,
                'version' => '1.0.0'
            ]);

            foreach ($source->regions as $region) {
                $duplicate->regions()->create($region->toArray());
            }

            DB::commit();
            $this->clearCache();
            $this->compileTemplate($duplicate);

            return $duplicate->load('regions');
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TemplateRepositoryException("Failed to duplicate template: {$e->getMessage()}", 0, $e);
        }
    }

    protected function compileTemplate(Model $template): void
    {
        $path = resource_path("views/templates/{$template->slug}.blade.php");
        File::put($path, $template->content);
        View::compileBladeString($template->content);
    }

    protected function removeCompiledTemplate(Model $template): void
    {
        $path = resource_path("views/templates/{$template->slug}.blade.php");
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    protected function incrementVersion(string $version): string
    {
        $parts = explode('.', $version);
        $parts[2] = (int)$parts[2] + 1;
        return implode('.', $parts);
    }

    protected function clearCache(): void
    {
        Cache::tags(['templates'])->flush();
    }
}
