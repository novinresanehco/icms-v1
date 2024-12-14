<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\CMS\Models\{Content, Category, Media};

class ContentManager
{
    private SecurityManager $security;
    private MediaHandler $media;

    public function __construct(SecurityManager $security, MediaHandler $media)
    {
        $this->security = $security;
        $this->media = $media;
    }

    public function create(array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = new Content($this->validateContentData($data));
            $content->save();

            if (isset($data['media'])) {
                $this->media->attachToContent($content, $data['media']);
            }

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            $this->updateCache($content);
            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Content
    {
        DB::beginTransaction();
        try {
            $content = Content::findOrFail($id);
            $content->update($this->validateContentData($data));

            if (isset($data['media'])) {
                $this->media->updateContentMedia($content, $data['media']);
            }

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            $this->updateCache($content);
            DB::commit();
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function publish(int $id): void
    {
        DB::beginTransaction();
        try {
            $content = Content::findOrFail($id);
            $content->publish();
            $this->updateCache($content);
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        DB::beginTransaction();
        try {
            $content = Content::findOrFail($id);
            $this->media->detachFromContent($content);
            $content->categories()->detach();
            $content->delete();
            
            $this->clearCache($content);
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function find(int $id): ?Content
    {
        return Cache::remember(
            "content:{$id}",
            3600,
            fn() => Content::with(['categories', 'media'])->find($id)
        );
    }

    private function validateContentData(array $data): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'categories' => 'array',
            'media' => 'array'
        ];

        return validator($data, $rules)->validate();
    }

    private function updateCache(Content $content): void
    {
        Cache::put(
            "content:{$content->id}",
            $content->load(['categories', 'media']),
            3600
        );
    }

    private function clearCache(Content $content): void
    {
        Cache::forget("content:{$content->id}");
    }
}

class MediaHandler
{
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    private const MAX_SIZE = 10485760; // 10MB

    public function attachToContent(Content $content, array $files): void
    {
        foreach ($files as $file) {
            $this->validateFile($file);
            
            $media = new Media([
                'filename' => $this->generateFilename($file),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize()
            ]);

            $file->storeAs('media', $media->filename);
            $content->media()->save($media);
        }
    }

    public function updateContentMedia(Content $content, array $files): void
    {
        $content->media()->delete();
        $this->attachToContent($content, $files);
    }

    public function detachFromContent(Content $content): void
    {
        foreach ($content->media as $media) {
            Storage::delete("media/{$media->filename}");
        }
        $content->media()->delete();
    }

    private function validateFile($file): void
    {
        if (!in_array($file->getMimeType(), self::ALLOWED_TYPES)) {
            throw new \InvalidArgumentException('Invalid file type');
        }

        if ($file->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException('File too large');
        }
    }

    private function generateFilename($file): string
    {
        return Str::uuid() . '.' . $file->getClientOriginalExtension();
    }
}