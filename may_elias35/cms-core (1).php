<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{ContentException, ValidationException};

class ContentManager
{
    private SecurityManager $security;
    private ContentRepository $repository;
    private MediaHandler $media;

    public function __construct(
        SecurityManager $security,  
        ContentRepository $repository,
        MediaHandler $media
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->media = $media;
    }

    public function createContent(array $data, array $media = []): Content 
    {
        return DB::transaction(function() use ($data, $media) {
            // Core content creation
            $content = $this->repository->create($data);
            
            // Handle any media
            if (!empty($media)) {
                $this->media->attachToContent($content->id, $media);
            }

            Cache::tags(['content'])->flush();
            
            return $content;
        });
    }

    public function updateContent(int $id, array $data): Content
    {
        return DB::transaction(function() use ($id, $data) {
            $content = $this->repository->findOrFail($id);
            $content->update($data);
            
            Cache::tags(['content'])->flush();
            
            return $content;
        });
    }

    public function deleteContent(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $content = $this->repository->findOrFail($id);
            
            // Remove associated media
            $this->media->removeFromContent($id);
            
            $result = $content->delete();
            Cache::tags(['content'])->flush();
            
            return $result;
        });
    }
}

class ContentRepository
{
    private Content $model;

    public function __construct(Content $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Content
    {
        return $this->model->create($this->validate($data));
    }

    public function findOrFail(int $id): Content
    {
        return Cache::tags(['content'])->remember(
            "content.{$id}",
            3600,
            fn() => $this->model->findOrFail($id)
        );
    }

    public function update(Content $content, array $data): bool
    {
        return $content->update($this->validate($data));
    }

    private function validate(array $data): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
        ];

        $validator = validator($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }

        return $data;
    }
}

class MediaHandler
{
    private string $disk = 'public';
    
    public function attachToContent(int $contentId, array $files): void
    {
        foreach ($files as $file) {
            $path = $file->store('content', $this->disk);
            
            Media::create([
                'content_id' => $contentId,
                'path' => $path,
                'type' => $file->getClientMimeType(),
                'size' => $file->getSize()
            ]);
        }
    }

    public function removeFromContent(int $contentId): void
    {
        $media = Media::where('content_id', $contentId)->get();
        
        foreach ($media as $item) {
            Storage::disk($this->disk)->delete($item->path);
            $item->delete();
        }
    }
}

class Content extends Model
{
    protected $fillable = [
        'title',
        'content', 
        'status',
        'author_id'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function media()
    {
        return $this->hasMany(Media::class);
    }
}

class Media extends Model 
{
    protected $fillable = [
        'content_id',
        'path',
        'type',
        'size'
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }
}
