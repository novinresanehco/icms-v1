<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;

class ContentManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ContentRepository $repository;
    private MediaHandler $media;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ContentRepository $repository,
        MediaHandler $media
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->repository = $repository;
        $this->media = $media;
    }

    public function create(array $data, ?array $media = null): Content
    {
        return $this->security->executeSecureOperation(function() use ($data, $media) {
            DB::beginTransaction();
            try {
                $content = $this->repository->create($data);
                
                if ($media) {
                    $this->media->attachToContent($content->id, $media);
                }
                
                $this->cache->forget("content:{$content->id}");
                DB::commit();
                
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['action' => 'create_content']);
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeSecureOperation(function() use ($id, $data) {
            DB::beginTransaction();
            try {
                $content = $this->repository->update($id, $data);
                $this->cache->forget("content:{$id}");
                DB::commit();
                return $content;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['action' => 'update_content', 'id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecureOperation(function() use ($id) {
            DB::beginTransaction();
            try {
                $this->media->detachFromContent($id);
                $result = $this->repository->delete($id);
                $this->cache->forget("content:{$id}");
                DB::commit();
                return $result;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }, ['action' => 'delete_content', 'id' => $id]);
    }

    public function get(int $id): ?Content
    {
        return $this->cache->remember("content:{$id}", 3600, fn() => 
            $this->repository->find($id)
        );
    }
}

class MediaHandler
{
    private string $basePath = 'uploads';

    public function store(UploadedFile $file): string
    {
        $path = $file->store($this->basePath);
        return $path;
    }

    public function attachToContent(int $contentId, array $media): void
    {
        foreach ($media as $file) {
            if ($file instanceof UploadedFile) {
                $path = $this->store($file);
                DB::table('content_media')->insert([
                    'content_id' => $contentId,
                    'path' => $path
                ]);
            }
        }
    }

    public function detachFromContent(int $contentId): void
    {
        DB::table('content_media')->where('content_id', $contentId)->delete();
    }
}

class ContentRepository
{
    public function create(array $data): Content
    {
        return Content::create($data);
    }

    public function update(int $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        $content->update($data);
        return $content;
    }

    public function find(int $id): ?Content
    {
        return Content::find($id);
    }

    public function delete(int $id): bool
    {
        return Content::destroy($id) > 0;
    }
}

class Content extends Model
{
    protected $fillable = [
        'title',
        'content',
        'status',
        'author_id',
        'category_id'
    ];

    protected $casts = [
        'status' => 'integer',
        'author_id' => 'integer',
        'category_id' => 'integer'
    ];
}
