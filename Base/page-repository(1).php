<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Page;
use Illuminate\Database\Eloquent\Collection;

interface PageRepositoryInterface extends RepositoryInterface
{
    public function findBySlug(string $slug): ?Page;
    public function findPublished(array $columns = ['*']): Collection;
    public function updateContent(int $id, string $content): Page;
    public function updateMetadata(int $id, array $metadata): Page;
    public function publish(int $id): Page;
    public function unpublish(int $id): Page;
    public function findByTemplate(string $template): Collection;
}

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\PageRepositoryInterface;
use App\Models\Page;
use Illuminate\Database\Eloquent\Collection;

class PageRepository extends BaseRepository implements PageRepositoryInterface
{
    public function __construct(Page $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Page
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function findPublished(array $columns = ['*']): Collection
    {
        return $this->model->where('status', 'published')
            ->orderBy('published_at', 'desc')
            ->get($columns);
    }

    public function updateContent(int $id, string $content): Page
    {
        $page = $this->findOrFail($id);
        $page->content = $content;
        $page->save();
        
        return $page;
    }

    public function updateMetadata(int $id, array $metadata): Page
    {
        $page = $this->findOrFail($id);
        $page->metadata = array_merge($page->metadata ?? [], $metadata);
        $page->save();
        
        return $page;
    }

    public function publish(int $id): Page
    {
        $page = $this->findOrFail($id);
        $page->status = 'published';
        $page->published_at = now();
        $page->save();
        
        return $page;
    }

    public function unpublish(int $id): Page
    {
        $page = $this->findOrFail($id);
        $page->status = 'draft';
        $page->save();
        
        return $page;
    }

    public function findByTemplate(string $template): Collection
    {
        return $this->model->where('template', $template)->get();
    }
}
