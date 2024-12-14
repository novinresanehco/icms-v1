<?php

namespace App\Core\CMS;

class ContentController
{
    private ContentRepository $content;
    private SecurityManager $security;
    private CacheManager $cache;

    public function store(Request $request): JsonResponse
    {
        $this->security->authorize('content.create');
        
        DB::beginTransaction();
        try {
            $content = $this->content->create($request->validated());
            DB::commit();
            return response()->json($content, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->security->authorize('content.update');

        DB::beginTransaction();
        try {
            $content = $this->content->update($id, $request->validated());
            DB::commit();
            return response()->json($content);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->security->authorize('content.view');
        
        $key = "content.list.{$request->page}";
        $content = $this->cache->remember($key, fn() =>
            $this->content->paginate()
        );
        
        return response()->json($content);
    }

    public function show(int $id): JsonResponse
    {
        $this->security->authorize('content.view');
        
        $content = $this->cache->remember("content.{$id}", fn() =>
            $this->content->findOrFail($id)
        );
        
        return response()->json($content);
    }
}

class MediaManager
{
    private StorageManager $storage;
    private string $disk = 'media';

    public function store(UploadedFile $file): string
    {
        return $this->storage->disk($this->disk)->store($file);
    }

    public function delete(string $path): bool
    {
        return $this->storage->disk($this->disk)->delete($path);
    }
}