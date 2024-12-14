<?php

namespace App\Core\Repositories\Decorators;

use App\Core\Repositories\Contracts\PageRepositoryInterface;
use App\Models\Page;
use Illuminate\Database\Eloquent\Collection;

class CacheablePageRepository extends CacheableRepository implements PageRepositoryInterface
{
    protected PageRepositoryInterface $repository;

    public function __construct(PageRepositoryInterface $repository)
    {
        parent::__construct($repository);
        $this->repository = $repository;
    }

    public function findBySlug(string $slug): ?Page
    {
        return $this->rememberCache('slug', compact('slug'), function () use ($slug) {
            return $this->repository->findBySlug($slug);
        });
    }

    public function findPublished(array $columns = ['*']): Collection
    {
        return $this->rememberCache('published', compact('columns'), function () use ($columns) {
            return $this->repository->findPublished($columns);
        });
    }

    public function updateContent(int $id, string $content): Page
    {
        $page = $this->repository->updateContent($id, $content);
        $this->flushCache();
        return $page;
    }

    public function updateMetadata(int $id, array $metadata): Page
    {
        $page = $this->repository->updateMetadata($id, $metadata);
        $this->flushCache();
        return $page;
    }

    public function publish(int $id): Page
    {
        $page = $this->repository->publish($id);
        $this->flushCache();
        return $page;
    }

    public function unpublish(int $id): Page
    {
        $page = $this->repository->unpublish($id);
        $this->flushCache();
        return $page;
    }

    public function findByTemplate(string $template): Collection
    {
        return $this->rememberCache('template', compact('template'), function () use ($template) {
            return $this->repository->findByTemplate($template);
        });
    }
}
