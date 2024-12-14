<?php

namespace App\Repositories;

use App\Models\Template;
use App\Repositories\Contracts\TemplateRepositoryInterface;
use Illuminate\Support\Collection;

class TemplateRepository extends BaseRepository implements TemplateRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'path'];
    protected array $filterableFields = ['type', 'status', 'category'];
    protected array $relationships = ['variables', 'layouts'];

    public function findByPath(string $path): ?Template
    {
        return Cache::remember(
            $this->getCacheKey("path.{$path}"),
            $this->cacheTTL,
            fn() => $this->model->where('path', $path)->first()
        );
    }

    public function getActive(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('active'),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('status', 'active')
                ->orderBy('name')
                ->get()
        );
    }

    public function getByType(string $type): Collection
    {
        return Cache::remember(
            $this->getCacheKey("type.{$type}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('type', $type)
                ->get()
        );
    }

    public function compileTemplate(int $id, array $variables = []): string
    {
        $template = $this->findOrFail($id);
        
        return view()
            ->file($template->path, $variables)
            ->render();
    }

    public function duplicate(int $id, string $newName): Template
    {
        try {
            $original = $this->findOrFail($id);
            $duplicate = $original->replicate();
            $duplicate->name = $newName;
            $duplicate->path = $this->generateUniquePath($newName);
            $duplicate->save();
            
            // Duplicate related data
            foreach ($original->variables as $variable) {
                $duplicate->variables()->create($variable->toArray());
            }
            
            $this->clearModelCache();
            return $duplicate;
            
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to duplicate template: {$e->getMessage()}");
        }
    }

    protected function generateUniquePath(string $name): string
    {
        $basePath = Str::slug($name);
        $path = $basePath;
        $counter = 1;
        
        while ($this->model->where('path', $path)->exists()) {
            $path = "{$basePath}-{$counter}";
            $counter++;
        }
        
        return $path;
    }
}
