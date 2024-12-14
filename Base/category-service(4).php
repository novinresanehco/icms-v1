<?php

namespace App\Core\Services;

use App\Core\Repositories\CategoryRepository;
use App\Core\Events\{CategoryCreated, CategoryUpdated, CategoryDeleted};
use App\Core\Exceptions\ServiceException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Event, Cache};

class CategoryService extends BaseService
{
    protected array $validators = [
        CategoryNameValidator::class,
        CategorySlugValidator::class
    ];

    public function __construct(CategoryRepository $repository)
    {
        parent::__construct($repository);
    }

    public function getTree(): Collection
    {
        return Cache::remember('category.tree', 3600, function() {
            return $this->repository->getActive()->toTree();
        });
    }

    public function reorder(array $items): bool
    {
        try {
            DB::beginTransaction();

            $updated = $this->repository->reorder($items);

            if ($updated) {
                Cache::tags(['categories'])->flush();
            }

            DB::commit();

            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServiceException("Failed to reorder categories: {$e->getMessage()}");
        }
    }

    public function moveToParent(Model $category, ?Model $parent): bool
    {
        try {
            if ($parent && $this->wouldCreateCycle($category, $parent)) {
                throw new ServiceException('Moving category would create cycle');
            }

            DB::beginTransaction();

            $updated = $this->repository->update($category, [
                'parent_id' => $parent ? $parent->id : null
            ]);

            if ($updated) {
                Cache::tags(['categories'])->flush();
            }

            DB::commit();

            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServiceException("Failed to move category: {$e->getMessage()}");
        }
    }

    protected function wouldCreateCycle(Model $category, Model $parent): bool
    {
        if ($category->id === $parent->id) {
            return true;
        }

        $currentParent = $parent;
        while ($currentParent->parent_id) {
            if ($currentParent->parent_id === $category->id) {
                return true;
            }
            $currentParent = $currentParent->parent;
        }

        return false;
    }

    protected function afterCreate(Model $model, array $data): void
    {
        Cache::tags(['categories'])->flush();
        Event::dispatch(new CategoryCreated($model));
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        Cache::tags(['categories'])->flush();
        Event::dispatch(new CategoryUpdated($model));
    }

    protected function afterDelete(Model $model): void
    {
        Cache::tags(['categories'])->flush();
        Event::dispatch(new CategoryDeleted($model));
    }
}
