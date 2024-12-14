<?php

namespace App\Repositories;

use App\Models\Content;
use Illuminate\Support\Collection;
use App\Core\Repositories\AdvancedRepository;

class ContentRepository extends AdvancedRepository
{
    protected array $searchable = ['title', 'content', 'metadata'];
    protected array $with = ['author', 'categories', 'tags'];

    public function findWithRelations(int $id): ?Content
    {
        return $this->executeWithCache(__METHOD__, function() use ($id) {
            return $this->model
                ->with($this->with)
                ->find($id);
        }, $id);
    }

    public function search(array $criteria, array $relations = []): Collection
    {
        return $this->executeQuery(function() use ($criteria, $relations) {
            $query = $this->model->newQuery();

            foreach ($this->searchable as $field) {
                if (isset($criteria[$field])) {
                    $query->where($field, 'LIKE', "%{$criteria[$field]}%");
                }
            }

            if (!empty($relations)) {
                $query->with($relations);
            }

            return $query->get();
        });
    }

    public function createWithRelations(array $data, array $relations): Content
    {
        return $this->executeTransaction(function() use ($data, $relations) {
            $content = $this->create($data);
            
            foreach ($relations as $relation => $items) {
                $content->{$relation}()->sync($items);
            }

            $this->invalidateCache(__METHOD__, $content->id);
            
            return $content;
        });
    }

    public function updateWithRelations(int $id, array $data, array $relations): ?Content
    {
        return $this->executeTransaction(function() use ($id, $data, $relations) {
            $content = $this->findOrFail($id);
            $content->update($data);
            
            foreach ($relations as $relation => $items) {
                $content->{$relation}()->sync($items);
            }

            $this->invalidateCache(__METHOD__, $id);
            
            return $content;
        });
    }
}
