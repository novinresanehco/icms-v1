// File: app/Core/Repository/ContentRepository.php
<?php

namespace App\Core\Repository;

use App\Core\Repository\BaseRepository;
use App\Core\Repository\Concerns\HasCache;
use App\Models\Content;

class ContentRepository extends BaseRepository
{
    use HasCache;

    public function model(): string
    {
        return Content::class;
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->remember("content.slug.{$slug}", function () use ($slug) {
            return $this->model->where('slug', $slug)->first();
        });
    }

    public function findPublished(int $id): ?Content
    {
        return $this->remember("content.published.{$id}", function () use ($id) {
            return $this->model->published()->find($id);
        });
    }
}

// File: app/Core/Repository/TagRepository.php
<?php

namespace App\Core\Repository;

use App\Core\Repository\BaseRepository;
use App\Core\Repository\Concerns\HasCache;
use App\Models\Tag;

class TagRepository extends BaseRepository
{
    use HasCache;

    public function model(): string
    {
        return Tag::class;
    }

    public function findByName(string $name): ?Tag
    {
        return $this->remember("tag.name.{$name}", function () use ($name) {
            return $this->model->where('name', $name)->first();
        });
    }

    public function findPopular(int $limit = 10): Collection
    {
        return $this->remember("tags.popular.{$limit}", function () use ($limit) {
            return $this->model->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get();
        });
    }
}

// File: app/Core/Repository/MediaRepository.php
<?php

namespace App\Core\Repository;

use App\Core\Repository\BaseRepository;
use App\Core\Repository\Concerns\HasCache;
use App\Models\Media;

class MediaRepository extends BaseRepository
{
    use HasCache;

    public function model(): string
    {
        return Media::class;
    }

    public function findByType(string $type): Collection
    {
        return $this->remember("media.type.{$type}", function () use ($type) {
            return $this->model->where('type', $type)->get();
        });
    }

    public function findUnused(): Collection
    {
        return $this->remember('media.unused', function () {
            return $this->model->doesntHave('contents')->get();
        });
    }
}

// File: app/Core/Repository/CategoryRepository.php
<?php

namespace App\Core\Repository;

use App\Core\Repository\BaseRepository;
use App\Core\Repository\Concerns\HasCache;
use App\Models\Category;

class CategoryRepository extends BaseRepository
{
    use HasCache;

    public function model(): string
    {
        return Category::class;
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->remember("category.slug.{$slug}", function () use ($slug) {
            return $this->model->where('slug', $slug)->first();
        });
    }

    public function getTree(): Collection
    {
        return $this->remember('categories.tree', function () {
            return $this->model->whereNull('parent_id')
                ->with('children')
                ->get();
        });
    }
}
