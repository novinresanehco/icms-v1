<?php

namespace App\Core\Services;

use App\Core\Repositories\TagRepository;
use App\Core\Events\{TagCreated, TagUpdated, TagDeleted};
use App\Core\Exceptions\ServiceException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Event, Cache};
use Illuminate\Support\Str;

class TagService extends BaseService
{
    protected array $validators = [
        TagNameValidator::class,
        TagSlugValidator::class
    ];

    public function __construct(TagRepository $repository)
    {
        parent::__construct($repository);
    }

    public function findOrCreate(string $name): Model
    {
        $slug = Str::slug($name);
        
        $tag = $this->repository->findBySlug($slug);
        
        if (!$tag) {
            $tag = $this->create([
                'name' => $name,
                'slug' => $slug
            ]);
        }
        
        return $tag;
    }

    public function syncTags(Model $model, array $tags): void
    {
        try {
            DB::beginTransaction();

            $tagIds = collect($tags)->map(function ($tag) {
                return is_numeric($tag) ? $tag : $this->findOrCreate($tag)->id;
            });

            $oldTags = $model->tags->pluck('id')->toArray();
            
            $model->tags()->sync($tagIds);
            
            Event::dispatch(new TagsUpdated($model, $oldTags, $tagIds->toArray()));
            
            Cache::tags(['tags'])->flush();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ServiceException("Failed to sync tags: {$e->getMessage()}");
        }
    }

    public function getPopular(int $limit = 10): Collection
    {
        return Cache::remember('tags.popular', 3600, function() use ($limit) {
            return $this->repository->getPopular($limit);
        });
    }

    protected function afterCreate(Model $model, array $data): void
    {
        Cache::tags(['tags'])->flush();
        Event::dispatch(new TagCreated($model));
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        Cache::tags(['tags'])->flush();
        Event::dispatch(new TagUpdated($model));
    }

    protected function afterDelete(Model $model): void
    {
        Cache::tags(['tags'])->flush();
        Event::dispatch(new TagDeleted($model));
    }
}
