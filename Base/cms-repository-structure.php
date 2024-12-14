<?php

namespace App\Core\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RepositoryInterface
{
    public function find(int $id): ?Model;
    public function all(): Collection;
    public function create(array $data): Model;
    public function update(Model $model, array $data): bool;
    public function delete(Model $model): bool;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
}

interface ContentRepositoryInterface extends RepositoryInterface
{
    public function findBySlug(string $slug): ?Model;
    public function findPublished(): Collection;
    public function findByCategory(int $categoryId): Collection;
    public function search(string $query): Collection;
    public function updateStatus(Model $content, string $status): bool;
}

namespace App\Core\Repositories;

use App\Core\Contracts\ContentRepositoryInterface;
use App\Core\Models\Content;
use App\Core\Events\ContentCreated;
use App\Core\Events\ContentUpdated;
use App\Core\Events\ContentDeleted;
use App\Core\Exceptions\ContentValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;

abstract class BaseRepository
{
    protected Model $model;
    protected array $validationRules = [];
    protected int $cacheTTL = 3600; // 1 hour

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    protected function validateData(array $data): void
    {
        $validator = Validator::make($data, $this->validationRules);

        if ($validator->fails()) {
            throw new ContentValidationException($validator->errors()->first());
        }
    }

    protected function getCacheKey(string $method, ...$args): string
    {
        $modelName = class_basename($this->model);
        return "repository.{$modelName}.{$method}." . md5(serialize($args));
    }

    protected function clearModelCache(): void
    {
        $modelName = class_basename($this->model);
        Cache::tags(["repository.{$modelName}"])->flush();
    }
}

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    protected array $validationRules = [
        'title' => 'required|min:3|max:255',
        'slug' => 'required|unique:contents,slug',
        'content' => 'required',
        'category_id' => 'required|exists:categories,id',
        'status' => 'required|in:draft,published,archived'
    ];

    public function __construct(Content $model)
    {
        parent::__construct($model);
    }

    public function find(int $id): ?Model
    {
        return Cache::tags(["repository.content"])
            ->remember(
                $this->getCacheKey(__FUNCTION__, $id),
                $this->cacheTTL,
                fn() => $this->model->find($id)
            );
    }

    public function all(): Collection
    {
        return Cache::tags(["repository.content"])
            ->remember(
                $this->getCacheKey(__FUNCTION__),
                $this->cacheTTL,
                fn() => $this->model->all()
            );
    }

    public function create(array $data): Model
    {
        $this->validateData($data);

        $content = $this->model->create($data);
        $this->clearModelCache();
        
        Event::dispatch(new ContentCreated($content));
        
        return $content;
    }

    public function update(Model $model, array $data): bool
    {
        $this->validateData(array_merge($model->toArray(), $data));

        $updated = $model->update($data);
        
        if ($updated) {
            $this->clearModelCache();
            Event::dispatch(new ContentUpdated($model));
        }
        
        return $updated;
    }

    public function delete(Model $model): bool
    {
        $deleted = $model->delete();
        
        if ($deleted) {
            $this->clearModelCache();
            Event::dispatch(new ContentDeleted($model));
        }
        
        return $deleted;
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->paginate($perPage);
    }

    public function findBySlug(string $slug): ?Model
    {
        return Cache::tags(["repository.content"])
            ->remember(
                $this->getCacheKey(__FUNCTION__, $slug),
                $this->cacheTTL,
                fn() => $this->model->where('slug', $slug)->first()
            );
    }

    public function findPublished(): Collection
    {
        return Cache::tags(["repository.content"])
            ->remember(
                $this->getCacheKey(__FUNCTION__),
                $this->cacheTTL,
                fn() => $this->model
                    ->where('status', 'published')
                    ->where('published_at', '<=', now())
                    ->orderBy('published_at', 'desc')
                    ->get()
            );
    }

    public function findByCategory(int $categoryId): Collection
    {
        return Cache::tags(["repository.content"])
            ->remember(
                $this->getCacheKey(__FUNCTION__, $categoryId),
                $this->cacheTTL,
                fn() => $this->model
                    ->where('category_id', $categoryId)
                    ->where('status', 'published')
                    ->orderBy('published_at', 'desc')
                    ->get()
            );
    }

    public function search(string $query): Collection
    {
        return $this->model
            ->where('title', 'like', "%{$query}%")
            ->orWhere('content', 'like', "%{$query}%")
            ->where('status', 'published')
            ->get();
    }

    public function updateStatus(Model $content, string $status): bool
    {
        if (!in_array($status, ['draft', 'published', 'archived'])) {
            throw new ContentValidationException('Invalid status');
        }

        $data = ['status' => $status];
        if ($status === 'published') {
            $data['published_at'] = now();
        }

        return $this->update($content, $data);
    }
}
