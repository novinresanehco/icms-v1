<?php

namespace App\Repositories;

use App\Models\Layout;
use App\Repositories\Contracts\LayoutRepositoryInterface;
use Illuminate\Support\Collection;

class LayoutRepository extends BaseRepository implements LayoutRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['type', 'status'];
    protected array $relationships = ['sections', 'template'];

    public function getActiveLayouts(): Collection
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

    public function createWithSections(array $data, array $sections): Layout
    {
        try {
            DB::beginTransaction();
            
            $layout = $this->create($data);
            
            foreach ($sections as $section) {
                $layout->sections()->create([
                    'name' => $section['name'],
                    'position' => $section['position'],
                    'config' => $section['config'] ?? []
                ]);
            }
            
            DB::commit();
            $this->clearModelCache();
            return $layout->load('sections');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to create layout: {$e->getMessage()}");
        }
    }

    public function updateSections(int $layoutId, array $sections): Layout
    {
        try {
            DB::beginTransaction();
            
            $layout = $this->findOrFail($layoutId);
            $layout->sections()->delete();
            
            foreach ($sections as $section) {
                $layout->sections()->create([
                    'name' => $section['name'],
                    'position' => $section['position'],
                    'config' => $section['config'] ?? []
                ]);
            }
            
            DB::commit();
            $this->clearModelCache();
            return $layout->load('sections');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to update sections: {$e->getMessage()}");
        }
    }

    public function findByTemplate(int $templateId): ?Layout
    {
        return Cache::remember(
            $this->getCacheKey("template.{$templateId}"),
            $this->cacheTTL,
            fn() => $this->model->where('template_id', $templateId)->first()
        );
    }
}
