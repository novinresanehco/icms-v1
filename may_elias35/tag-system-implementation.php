<?php

namespace App\Core\Tag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'type'];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($tag) {
            $tag->slug = $tag->slug ?? Str::slug($tag->name);
        });
    }

    public function contents(): MorphToMany
    {
        return $this->morphedByMany(Content::class, 'taggable')
                    ->withTimestamps()
                    ->withPivot('order');
    }
}

namespace App\Core\Tag\Repositories;

use App\Core\Tag\Contracts\TagRepositoryInterface;
use App\Core\Tag\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class TagRepository implements TagRepositoryInterface
{
    private Tag $model;
    
    public function __construct(Tag $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Tag
    {
        $tag = $this->model->create($data);
        $this->clearTagCache();
        return $tag;
    }

    public function update(int $id, array $data): Tag
    {
        $tag = $this->find($id);
        $tag->update($data);
        $this->clearTagCache();
        return $tag;
    }

    public function delete(int $id): bool
    {
        $result = $this->model->destroy($id);
        $this->clearTagCache();
        return $result;
    }

    public function find(int $id): ?Tag
    {
        return Cache::tags(['tags'])->remember(
            "tag:{$id}",
            now()->addHours(24),
            fn() => $this->model->find($id)
        );
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        $content = app(ContentRepositoryInterface::class)->find($contentId);
        $content->tags()->sync($tagIds);
        $this->clearContentTagCache($contentId);
    }

    public function detachFromContent(int $contentId, array $tagIds): void
    {
        $content = app(ContentRepositoryInterface::class)->find($contentId);
        $content->tags()->detach($tagIds);
        $this->clearContentTagCache($contentId);
    }

    public function getContentTags(int $contentId): Collection
    {
        return Cache::tags(['content-tags'])->remember(
            "content:{$contentId}:tags",
            now()->addHours(24),
            function () use ($contentId) {
                $content = app(ContentRepositoryInterface::class)->find($contentId);
                return $content->tags()->orderBy('pivot_order')->get();
            }
        );
    }

    private function clearTagCache(): void
    {
        Cache::tags(['tags', 'content-tags'])->flush();
    }

    private function clearContentTagCache(int $contentId): void
    {
        Cache::tags(['content-tags'])->forget("content:{$contentId}:tags");
    }
}

namespace App\Core\Tag\Services;

use App\Core\Tag\Contracts\TagRepositoryInterface;
use App\Core\Tag\Exceptions\TagException;
use App\Core\Tag\Resources\TagResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TagService
{
    private TagRepositoryInterface $repository;

    public function __construct(TagRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function create(array $data): TagResource
    {
        $this->validateTagData($data);

        try {
            DB::beginTransaction();
            
            $tag = $this->repository->create($data);
            
            DB::commit();
            
            return new TagResource($tag);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tag creation failed', ['error' => $e->getMessage()]);
            throw new TagException('Failed to create tag: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $data): TagResource
    {
        $this->validateTagData($data);

        try {
            DB::beginTransaction();
            
            $tag = $this->repository->update($id, $data);
            
            DB::commit();
            
            return new TagResource($tag);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tag update failed', ['error' => $e->getMessage()]);
            throw new TagException('Failed to update tag: ' . $e->getMessage());
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();
            
            $result = $this->repository->delete($id);
            
            DB::commit();
            
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tag deletion failed', ['error' => $e->getMessage()]);
            throw new TagException('Failed to delete tag: ' . $e->getMessage());
        }
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        try {
            DB::beginTransaction();
            
            $this->repository->attachToContent($contentId, $tagIds);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tag attachment failed', ['error' => $e->getMessage()]);
            throw new TagException('Failed to attach tags: ' . $e->getMessage());
        }
    }

    private function validateTagData(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|max:50'
        ]);

        if ($validator->fails()) {
            throw new TagException('Invalid tag data: ' . $validator->errors()->first());
        }
    }
}

namespace App\Core\Tag\Http\Controllers;

use App\Core\Tag\Services\TagService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    private TagService $tagService;

    public function __construct(TagService $tagService)
    {
        $this->tagService = $tagService;
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $tag = $this->tagService->create($request->all());
            return response()->json($tag, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $tag = $this->tagService->update($id, $request->all());
            return response()->json($tag);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->tagService->delete($id);
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function attachToContent(Request $request, int $contentId): JsonResponse
    {
        try {
            $this->tagService->attachToContent($contentId, $request->input('tag_ids', []));
            return response()->json(['message' => 'Tags attached successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
