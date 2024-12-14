<?php

namespace App\Repositories;

use App\Models\Category;
use App\Core\Repositories\AbstractRepository;
use Illuminate\Support\Collection;

class CategoryRepository extends AbstractRepository
{
    protected array $searchable = ['name', 'slug', 'description'];
    protected array $with = ['parent', 'children'];

    public function getTree(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model->whereNull('parent_id')
                ->with(['children' => function($query) {
                    $query->orderBy('position');
                }])
                ->orderBy('position')
                ->get();
        });
    }

    public function reorder(array $positions): void
    {
        $this->beginTransaction();
        
        try {
            foreach ($positions as $id => $position) {
                $this->model->where('id', $id)->update(['position' => $position]);
            }
            
            $this->commit();
            
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function moveToParent(int $id, ?int $parentId): Category
    {
        $category = $this->findOrFail($id);
        $category->parent_id = $parentId;
        $category->save();
        
        $this->invalidateCache();
        return $category->fresh();
    }

    public function getBySlug(string $slug): ?Category
    {
        return $this->executeQuery(function() use ($slug) {
            return $this->model->where('slug', $slug)->first();
        });
    }

    public function findWithContent(int $id): Category
    {
        return $this->executeQuery(function() use ($id) {
            return $this->model->with(['contents' => function($query) {
                $query->published()->latest();
            }])->findOrFail($id);
        });
    }
}
