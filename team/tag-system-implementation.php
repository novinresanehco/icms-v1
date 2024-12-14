<?php

namespace App\Core\Tag;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Database Migration
Schema::create('tags', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->string('slug')->unique();
    $table->text('description')->nullable();
    $table->unsignedInteger('usage_count')->default(0);
    $table->json('metadata')->nullable();
    $table->timestamps();
    $table->softDeletes();
});

Schema::create('taggables', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tag_id')->constrained()->onDelete('cascade');
    $table->morphs('taggable');
    $table->unsignedInteger('order')->default(0);
    $table->timestamps();
    
    $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
});

// Model
namespace App\Core\Tag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tag extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'metadata'];
    
    protected $casts = [
        'metadata' => 'array',
        'usage_count' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($tag) {
            $tag->slug = Str::slug($tag->name);
        });
        
        static::updating(function ($tag) {
            if ($tag->isDirty('name')) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function taggables()
    {
        return $this->morphToMany(
            Content::class, 
            'taggable'
        )->withTimestamps()->withPivot('order');
    }
}

// Repository Interface
namespace App\Core\Tag\Repositories;

interface TagRepositoryInterface
{
    public function create(array $data): Tag;
    public function update(int $id, array $data): Tag;
    public function delete(int $id): bool;
    public function find(int $id): ?Tag;
    public function findBySlug(string $slug): ?Tag;
    public function getPopularTags(int $limit = 10): Collection;
    public function attachToContent(int $contentId, array $tagIds): void;
    public function detachFromContent(int $contentId, array $tagIds): void;
}

// Repository Implementation
namespace App\Core\Tag\Repositories;

class TagRepository implements TagRepositoryInterface
{
    protected Tag $model;
    protected CacheManager $cache;

    public function __construct(Tag $model, CacheManager $cache)
    {
        $this->model = $model;
        $this->cache = $cache;
    }

    public function create(array $data): Tag
    {
        $tag = $this->model->create($data);
        $this->cache->tags(['tags'])->flush();
        return $tag;
    }

    public function update(int $id, array $data): Tag
    {
        $tag = $this->find($id);
        $tag->update($data);
        $this->cache->tags(['tags'])->flush();
        return $tag->fresh();
    }

    public function find(int $id): ?Tag
    {
        return $this->cache->tags(['tags'])->remember(
            "tag:{$id}",
            3600,
            fn() => $this->model->find($id)
        );
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        return $this->cache->tags(['tags'])->remember(
            "popular_tags:{$limit}",
            3600,
            fn() => $this->model->orderBy('usage_count', 'desc')
                               ->limit($limit)
                               ->get()
        );
    }
}

// Service Interface
namespace App\Core\Tag\Services;

interface TagServiceInterface
{
    public function createTag(array $data): TagResource;
    public function updateTag(int $id, array $data): TagResource;
    public function deleteTag(int $id): bool;
    public function getPopularTags(int $limit = 10): Collection;
    public function attachTagsToContent(int $contentId, array $tagIds): void;
}

// Service Implementation
namespace App\Core\Tag\Services;

class TagService implements TagServiceInterface
{
    protected TagRepositoryInterface $repository;
    protected TagValidator $validator;
    protected EventDispatcher $events;

    public function __construct(
        TagRepositoryInterface $repository,
        TagValidator $validator,
        EventDispatcher $events
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function createTag(array $data): TagResource
    {
        $validatedData = $this->validator->validate($data);

        DB::beginTransaction();
        try {
            $tag = $this->repository->create($validatedData);
            $this->events->dispatch(new TagCreated($tag));
            
            DB::commit();
            return new TagResource($tag);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagCreationException($e->getMessage());
        }
    }

    public function attachTagsToContent(int $contentId, array $tagIds): void
    {
        DB::beginTransaction();
        try {
            $this->repository->attachToContent($contentId, $tagIds);
            $this->updateTagUsageCounts($tagIds);
            
            $this->events->dispatch(new TagsAttached($contentId, $tagIds));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagAttachmentException($e->getMessage());
        }
    }

    protected function updateTagUsageCounts(array $tagIds): void
    {
        foreach ($tagIds as $tagId) {
            $tag = $this->repository->find($tagId);
            $tag->increment('usage_count');
        }
    }
}

// Controller
namespace App\Core\Tag\Controllers;

class TagController extends Controller
{
    protected TagServiceInterface $tagService;

    public function __construct(TagServiceInterface $tagService)
    {
        $this->tagService = $tagService;
    }

    public function store(CreateTagRequest $request): JsonResponse
    {
        try {
            $tag = $this->tagService->createTag($request->validated());
            return response()->json($tag, Response::HTTP_CREATED);
        } catch (TagCreationException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function attachToContent(
        AttachTagsRequest $request, 
        int $contentId
    ): JsonResponse {
        try {
            $this->tagService->attachTagsToContent(
                $contentId,
                $request->input('tag_ids')
            );
            return response()->json(['message' => 'Tags attached successfully']);
        } catch (TagAttachmentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}

// Resource
namespace App\Core\Tag\Resources;

class TagResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'usage_count' => $this->usage_count,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString()
        ];
    }
}

// Events
namespace App\Core\Tag\Events;

class TagCreated
{
    public function __construct(public readonly Tag $tag) {}
}

class TagsAttached
{
    public function __construct(
        public readonly int $contentId,
        public readonly array $tagIds
    ) {}
}

// Feature Tests
namespace Tests\Feature\Tag;

class TagTest extends TestCase
{
    protected TagServiceInterface $tagService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tagService = app(TagServiceInterface::class);
    }

    /** @test */
    public function it_can_create_a_tag(): void
    {
        $data = [
            'name' => 'Test Tag',
            'description' => 'Test Description'
        ];

        $tag = $this->tagService->createTag($data);

        $this->assertDatabaseHas('tags', [
            'name' => 'Test Tag',
            'slug' => 'test-tag'
        ]);

        $this->assertEquals('Test Tag', $tag->name);
        $this->assertEquals('test-tag', $tag->slug);
    }

    /** @test */
    public function it_can_attach_tags_to_content(): void
    {
        $content = Content::factory()->create();
        $tags = Tag::factory()->count(3)->create();
        $tagIds = $tags->pluck('id')->toArray();

        $this->tagService->attachTagsToContent($content->id, $tagIds);

        foreach ($tagIds as $tagId) {
            $this->assertDatabaseHas('taggables', [
                'tag_id' => $tagId,
                'taggable_id' => $content->id,
                'taggable_type' => Content::class
            ]);
        }
    }

    /** @test */
    public function it_increments_usage_count_when_attaching_tags(): void
    {
        $content = Content::factory()->create();
        $tag = Tag::factory()->create(['usage_count' => 0]);

        $this->tagService->attachTagsToContent($content->id, [$tag->id]);

        $this->assertEquals(1, $tag->fresh()->usage_count);
    }
}
