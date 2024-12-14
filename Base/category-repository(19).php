<?php

namespace App\Repositories;

use App\Models\Category;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class CategoryRepository extends BaseRepository
{
    public function __construct(Category $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findWithChildren(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->with('children')->whereNull('parent_id')->get();
        });
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->executeWithCache(__FUNCTION__, [$slug], function () use ($slug) {
            return $this->model->where('slug', $slug)->first();
        });
    }

    public function findActiveCategories(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('status', 'active')
                             ->orderBy('order')
                             ->get();
        });
    }

    public function updateOrder(int $id, int $order): bool
    {
        $result = $this->update($id, ['order' => $order]);
        $this->clearCache($this->model->find($id));
        return $result;
    }

    public function findParentCategories(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->whereNull('parent_id')
                             ->where('status', 'active')
                             ->orderBy('order')
                             ->get();
        });
    }
}
