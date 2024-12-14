<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\{DB, Cache, Storage};
use App\Exceptions\{ContentException, ValidationException};

class ContentManager
{
    protected Repository $repository;
    protected ValidationService $validator;
    protected SecurityManager $security;
    protected MediaManager $media;

    public function store(array $data, Context $context): Content
    {
        return DB::transaction(function() use ($data, $context) {
            $validated = $this->validator->validate($data);
            
            $content = new Content([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'status' => ContentStatus::DRAFT,
                'user_id' => $context->userId,
                'type' => $validated['type']
            ]);

            $this->repository->save($content);

            if (isset($validated['media'])) {
                $this->media->attachToContent($content->id, $validated['media']);
            }

            Cache::tags(['content'])->flush();
            return $content;
        });
    }

    public function update(int $id, array $data, Context $context): Content
    {
        return DB::transaction(function() use ($id, $data, $context) {
            $content = $this->repository->findOrFail($id);
            $this->security->authorize($context, 'update', $content);
            
            $validated = $this->validator->validate($data);
            
            $content->fill([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'status' => $validated['status'] ?? $content->status
            ]);

            if ($content->isDirty()) {
                $this->repository->save($content);
                $this->createRevision($content);
            }

            if (isset($validated['media'])) {
                $this->media->syncWithContent($content->id, $validated['media']);
            }

            Cache::tags(['content'])->flush();
            return $content;
        });
    }

    protected function createRevision(Content $content): void
    {
        $revision = new ContentRevision([
            'content_id' => $content->id,
            'title' => $content->title,
            'body' => $content->body,
            'user_id' => auth()->id(),
            'version' => $content->revisions()->count() + 1
        ]);

        $revision->save();
    }
}

class MediaManager
{
    protected string $disk = 'public';
    protected array $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    protected int $maxSize = 5242880; // 5MB

    public function store(UploadedFile $file, Context $context): Media
    {
        $this->validateFile($file);

        return DB::transaction(function() use ($file, $context) {
            $path = $this->storeFile($file);
            
            $media = new Media([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'user_id' => $context->userId
            ]);

            $media->save();
            return $media;
        });
    }

    public function attachToContent(int $contentId, array $mediaIds): void
    {
        $content = Content::findOrFail($contentId);
        $content->media()->sync($mediaIds);
    }

    protected function validateFile(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), $this->allowedTypes)) {
            throw new ValidationException('Invalid file type');
        }

        if ($file->getSize() > $this->maxSize) {
            throw new ValidationException('File too large');
        }
    }

    protected function storeFile(UploadedFile $file): string
    {
        $name = md5(uniqid()) . '.' . $file->getClientOriginalExtension();
        return Storage::disk($this->disk)->putFileAs('media', $file, $name);
    }
}

class Repository
{
    protected Builder $query;
    protected array $with = [];

    public function find(int $id): ?Model
    {
        return $this->query->with($this->with)->find($id);
    }

    public function findOrFail(int $id): Model
    {
        return $this->query->with($this->with)->findOrFail($id);
    }

    public function save(Model $model): bool
    {
        return $model->save();
    }

    public function delete(Model $model): bool
    {
        return $model->delete();
    }
}

class SecurityManager
{
    public function authorize(Context $context, string $action, Model $model): void
    {
        if (!$this->can($context, $action, $model)) {
            throw new SecurityException('Unauthorized action');
        }
    }

    protected function can(Context $context, string $action, Model $model): bool
    {
        if ($action === 'update' && $model->user_id !== $context->userId) {
            return false;
        }

        return true;
    }
}

class ValidationService
{
    public function validate(array $data): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'type' => ['required', 'string', 'in:page,post,article'],
            'status' => ['string', 'in:draft,published,archived'],
            'media' => ['array'],
            'media.*' => ['integer', 'exists:media,id']
        ];

        $validator = validator($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }

        return $validator->validated();
    }
}

class Content extends Model
{
    protected $fillable = [
        'title',
        'body',
        'status',
        'user_id',
        'type'
    ];

    public function revisions()
    {
        return $this->hasMany(ContentRevision::class);
    }

    public function media()
    {
        return $this->belongsToMany(Media::class);
    }
}

class Media extends Model
{
    protected $fillable = [
        'filename',
        'path',
        'mime_type',
        'size',
        'user_id'
    ];

    public function contents()
    {
        return $this->belongsToMany(Content::class);
    }
}
