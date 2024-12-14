<?php

namespace App\Core\Category\Services;

use App\Core\Category\Models\Category;
use App\Core\Category\Repositories\CategoryRepository;
use App\Core\Category\Events\{
    CategoryCreated,
    CategoryUpdated,
    CategoryDeleted,
    CategoryMoved
};
use Illuminate\Support\Facades\{DB, Cache, Event};

class CategoryHandlerService
{
    public function __construct(
        private CategoryRepository $repository,
        private CategoryValidator $validator,
        private CategoryTreeManager $treeManager
    ) {}

    public function handleCreate(array $data): Category
    {
        $this->validator->validateCreate($data);

        return DB::transaction(function () use ($data) {
            $category = $this->repository->create($data);
            
            if (isset($data['parent_id'])) {
                $this->treeManager->moveNode($category, $data['parent_id']);
            }

            event(new CategoryCreated($category));
            Cache::tags(['categories'])->flush();

            return $category;
        });
    }

    public function handleUpdate(int $id, array $data): Category
    {
        $category = $this->repository->findOrFail($id);
        $this->validator->validateUpdate($category, $data);

        return DB::transaction(function () use ($category, $data) {
            $oldParentId = $category->parent_id;
            
            $category = $this->repository->update($category, $data);

            if (isset($data['parent_id']) && $oldParentId !== $data['parent_id']) {
                $this->treeManager->moveNode($category, $data['parent_id']);
                event(new CategoryMoved($category, $oldParentId, $data['parent_id']));
            }

            event(new CategoryUpdated($category));
            Cache::tags(['categories'])->flush();

            return $category;
        });
    }

    public function handleDelete(int $id, bool $force = false): bool
    {
        $category = $this->repository->findOrFail($id);
        $this->validator->validateDelete($category);

        return DB::transaction(function () use ($category, $force) {
            if ($force) {
                $result = $this->repository->forceDelete($category);
            } else {
                $result = $this->repository->delete($category);
            }

            if ($result) {
                event(new CategoryDeleted($category));
                Cache::tags(['categories'])->flush();
            }

            return $result;
        });
    }

    public function handleMove(int $id, ?int $parentId, int $position = 0): Category
    {
        $category = $this->repository->findOrFail($id);
        $this->validator->validateMove($category, $parentId);

        return DB::transaction(function () use ($category, $parentId, $position) {
            $oldParentId = $category->parent_id;
            
            $this->treeManager->moveNode($category, $parentId, $position);
            
            event(new CategoryMoved($category, $oldParentId, $parentId));
            Cache::tags(['categories'])->flush();

            return $category->fresh();
        });
    }

    public function handleReorder(array $order): array
    {
        $this->validator->validateReorder($order);

        return DB::transaction(function () use ($order) {
            $results = $this->treeManager->reorderNodes($order);
            Cache::tags(['categories'])->flush();
            return $results;
        });
    }

    public function handleList(array $filters = []): array
    {
        return $this->repository->getTreeWithFilters($filters);
    }

    public function handleShow(int $id): Category
    {
        return $this->repository->findWithRelations($id);
    }

    public function handleBulkOperation(string $action, array $ids, array $data = []): array
    {
        $this->validator->validateBulkOperation($action, $ids, $data);

        return DB::transaction(function () use ($action, $ids, $data) {
            $results = [];

            foreach ($ids as $id) {
                try {
                    switch ($action) {
                        case 'delete':
                            $results[$id] = $this->handleDelete($id);
                            break;
                        case 'update':
                            $results[$id] = $this->handleUpdate($id, $data);
                            break;
                        case 'move':
                            $results[$id] = $this->handleMove($id, $data['parent_id'] ?? null);
                            break;
                    }
                } catch (\Exception $e) {
                    $results[$id] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            Cache::tags(['categories'])->flush();
            return $results;
        });
    }
}
