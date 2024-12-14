<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Models\{Tag, Taggable};
use App\Core\Services\{SecurityService, ValidationService};
use App\Core\Exceptions\{TagException, ValidationException};

class TagManager
{
    private SecurityService $security;
    private ValidationService $validator;
    private array $config;

    private const CACHE_TTL = 3600;
    private const MAX_TAGS = 50;
    private const MAX_TAG_LENGTH = 50;

    public function __construct(
        SecurityService $security,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function createTag(array $data): Tag
    {
        return $this->security->executeSecure(function() use ($data) {
            DB::beginTransaction();
            try {
                $this->validateTagData($data);
                $normalized = $this->normalizeTagName($data['name']);
                
                $tag = Tag::firstOrCreate(
                    ['normalized_name' => $normalized],
                    [
                        'name' => $data['name'],
                        'type' => $data['type'] ?? 'general',
                        'meta' => $data['meta'] ?? []
                    ]
                );

                $this->clearTagCache();
                DB::commit();
                
                return $tag;
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new TagException('Failed to create tag: ' . $e->getMessage());
            }
        }, 'tag.create');
    }

    public function attachTags($model, array $tags): void
    {
        $this->security->executeSecure(function() use ($model, $tags) {
            DB::beginTransaction();
            try {
                $this->validateTagging($model, $tags);
                $tagIds = $this->resolveTagIds($tags);
                
                $existingIds = $model->tags()->pluck('id')->toArray();
                $newIds = array_diff($tagIds, $existingIds);
                
                if (!empty($newIds)) {
                    $model->tags()->attach($newIds, [
                        'created_at' => now(),
                        'created_by' => auth()->id()
                    ]);
                }

                $this->clearModelTagCache($model);
                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new TagException('Failed to attach tags: ' . $e->getMessage());
            }
        }, 'tag.attach');
    }

    public function detachTags($model, array $tags): void
    {
        $this->security->executeSecure(function() use ($model, $tags) {
            DB::beginTransaction();
            try {
                $tagIds = $this->resolveTagIds($tags);
                $model->tags()->detach($tagIds);
                
                $this->clearModelTagCache($model);
                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new TagException('Failed to detach tags: ' . $e->getMessage());
            }
        }, 'tag.detach');
    }

    public function syncTags($model, array $tags): void
    {
        $this->security->executeSecure(function() use ($model, $tags) {
            DB::beginTransaction();
            try {
                $this->validateTagging($model, $tags);
                $tagIds = $this->resolveTagIds($tags);
                
                $model->tags()->sync($tagIds, [
                    'created_at' => now(),
                    'created_by' => auth()->id()
                ]);

                $this->clearModelTagCache($model);
                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new TagException('Failed to sync tags: ' . $e->getMessage());
            }
        }, 'tag.sync');
    }

    public function findByTag(string $tag, string $type = null): array
    {
        return $this->security->executeSecure(function() use ($tag, $type) {
            $normalized = $this->normalizeTagName($tag);
            
            return Cache::remember(
                "tag.{$normalized}.{$type}",
                self::CACHE_TTL,
                function() use ($normalized, $type) {
                    $query = Taggable::whereHas('tag', function($q) use ($normalized, $type) {
                        $q->where('normalized_name', $normalized);
                        if ($type) {
                            $q->where('type', $type);
                        }
                    });

                    return $query->with('taggable')
                        ->get()
                        ->pluck('taggable')
                        ->toArray();
                }
            );
        }, 'tag.read');
    }

    public function getPopularTags(string $type = null, int $limit = 10): array
    {
        return Cache::remember(
            "popular_tags.{$type}.{$limit}",
            self::CACHE_TTL,
            function() use ($type, $limit) {
                $query = Tag::withCount('taggables');
                
                if ($type) {
                    $query->where('type', $type);
                }

                return $query->orderByDesc('taggables_count')
                    ->limit($limit)
                    ->get()
                    ->toArray();
            }
        );
    }

    protected function validateTagData(array $data): void
    {
        $rules = [
            'name' => ['required', 'string', "max:" . self::MAX_TAG_LENGTH],
            'type' => 'sometimes|string|max:50',
            'meta' => 'sometimes|array'
        ];

        $this->validator->validate($data, $rules);
        
        // Additional validation
        if (!preg_match('/^[\p{L}\p{N}\s\-\_\.]+$/u', $data['name'])) {
            throw new ValidationException('Invalid tag name format');
        }
    }

    protected function validateTagging($model, array $tags): void
    {
        if (count($tags) > self::MAX_TAGS) {
            throw new ValidationException("Maximum of " . self::MAX_TAGS . " tags allowed");
        }

        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $this->validateTagData(['name' => $tag]);
            }
        }
    }

    protected function normalizeTagName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/\s+/', '-', $normalized);
        return $normalized;
    }

    protected function resolveTagIds(array $tags): array
    {
        $ids = [];
        
        foreach ($tags as $tag) {
            if (is_numeric($tag)) {
                $ids[] = $tag;
            } else {
                $ids[] = $this->createTag(['name' => $tag])->id;
            }
        }
        
        return array_unique($ids);
    }

    protected function clearTagCache(): void
    {
        Cache::tags(['tags'])->flush();
    }

    protected function clearModelTagCache($model): void
    {
        $modelType = get_class($model);
        $modelId = $model->id;
        
        Cache::forget("model_tags.{$modelType}.{$modelId}");
        Cache::tags(['tags'])->flush();
    }
}
