<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManager
{
    protected SecurityManager $security;
    protected ContentRepository $repository;
    protected ContentValidator $validator;
    
    public function __construct(
        SecurityManager $security,
        ContentRepository $repository,
        ContentValidator $validator
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
    }

    public function createContent(array $data): Content
    {
        $this->validator->validateCreate($data);
        
        return DB::transaction(function() use ($data) {
            $content = $this->repository->create($data);
            Cache::tags('content')->flush();
            return $content;
        });
    }

    public function updateContent(int $id, array $data): Content
    {
        $this->validator->validateUpdate($id, $data);
        
        return DB::transaction(function() use ($id, $data) {
            $content = $this->repository->update($id, $data);
            Cache::tags(['content', "content:$id"])->flush();
            return $content;
        });
    }

    public function deleteContent(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $result = $this->repository->delete($id);
            Cache::tags(['content', "content:$id"])->flush();
            return $result;
        });
    }

    public function getContent(int $id): Content
    {
        return Cache::tags("content:$id")->remember(
            "content:$id",
            3600,
            fn() => $this->repository->find($id)
        );
    }
}

class ContentRepository
{
    public function create(array $data): Content
    {
        return Content::create($this->prepareData($data));
    }

    public function update(int $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        $content->update($this->prepareData($data));
        return $content->fresh();
    }

    public function delete(int $id): bool
    {
        return Content::destroy($id) > 0;
    }

    public function find(int $id): Content
    {
        return Content::findOrFail($id);
    }

    protected function prepareData(array $data): array
    {
        return array_merge($data, [
            'updated_at' => now(),
            'updated_by' => auth()->id()
        ]);
    }
}

class Content extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'published_at' => 'datetime',
        'metadata' => 'array'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
                    ->where('published_at', '<=', now());
    }
}

class ContentValidator
{
    public function validateCreate(array $data): void
    {
        $this->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category_id' => 'required|exists:categories,id',
            'status' => 'required|in:draft,published',
            'published_at' => 'nullable|date'
        ]);
    }

    public function validateUpdate(int $id, array $data): void
    {
        $this->validate($data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'category_id' => 'exists:categories,id',
            'status' => 'in:draft,published',
            'published_at' => 'nullable|date'
        ]);
    }

    protected function validate(array $data, array $rules): void
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }
}

class ContentController
{
    protected ContentManager $manager;

    public function __construct(ContentManager $manager)
    {
        $this->manager = $manager;
    }

    public function store(Request $request)
    {
        try {
            $content = $this->manager->createContent($request->all());
            return response()->json($content, 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $content = $this->manager->updateContent($id, $request->all());
            return response()->json($content);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function destroy(int $id)
    {
        $this->manager->deleteContent($id);
        return response()->json(null, 204);
    }

    public function show(int $id)
    {
        return response()->json($this->manager->getContent($id));
    }
}
