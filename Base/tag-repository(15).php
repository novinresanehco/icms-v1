<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Core\Repositories\AbstractRepository;
use Illuminate\Support\Collection;

class TagRepository extends AbstractRepository
{
    protected array $searchable = ['name', 'slug', 'description'];

    public function findByNames(array $names): Collection
    {
        return $this->executeQuery(function() use ($names) {
            return $this->model->whereIn('name', $names)->get();
        });
    }

    public function syncContent(int $contentId, array $tagIds): void
    {
        $this->beginTransaction();
        try {
            $content = app(ContentRepository::class)->findOrFail($contentId);
            $content->tags()->sync($tagIds);
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function getPopular(int $limit = 10): Collection
    {
        return $this->executeQuery(function() use ($limit) {
            return $this->model->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get();
        });
    }

    public function getUnused(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model->doesntHave('contents')->get();
        });
    }

    public function deleteUnused(): int
    {
        $count = 0;
        $this->beginTransaction();
        
        try {
            $count = $this->model->doesntHave('contents')->delete();
            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }

        return $count;
    }
}
