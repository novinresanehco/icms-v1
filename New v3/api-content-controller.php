<?php

namespace App\Http\Controllers\Api;

use App\Core\Content\{
    ContentManager,
    MediaManager,
    SearchManager
};
use App\Core\Cache\CacheManager;

class ApiContentController extends Controller 
{
    private ContentManager $content;
    private MediaManager $media;
    private SearchManager $search;
    private CacheManager $cache;

    public function index(IndexContentRequest $request): JsonResponse
    {
        $query = $this->content->newQuery();

        if ($request->has('search')) {
            $query = $this->search->enhance($query, $request->search);
        }

        if ($request->has('filter')) {
            $query = $this->applyFilters($query, $request->filter);
        }

        $data = $query->paginate($request->perPage());

        return response()->json($data);
    }

    public function store(StoreContentRequest $request): JsonResponse
    {
        $this->authorize('create', Content::class);

        try {
            DB::beginTransaction();

            $content = $this->content->create($request->validated());

            if ($request->hasFile('media')) {
                $this->media->attachToContent(
                    $content, 
                    $request->file('media')
                );
            }

            $this->search->index($content);
            
            DB::commit();

            $this->cache->invalidateContentCache($content);

            return response()->json($content, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function show(int $id): JsonResponse 
    {
        $content = $this->content->findOrFail($id);
        
        $this->authorize('view', $content);

        return response()->json(
            $this->content->load($content, request('include', []))
        );
    }

    public function update(UpdateContentRequest $request, int $id): JsonResponse
    {
        $content = $this->content->findOrFail($id);
        
        $this->authorize('update', $content);

        try {
            DB::beginTransaction();

            $content = $this->content->update($content, $request->validated());

            if ($request->has('media')) {
                $this->media->syncWithContent($content, $request->media);
            }

            $this->search->update($content);
            
            DB::commit();

            $this->cache->invalidateContentCache($content);

            return response()->json($content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $content = $this->content->findOrFail($id);
        
        $this->authorize('delete', $content);

        try {
            DB::beginTransaction();

            $this->content->delete($content);
            $this->search->delete($content);
            
            DB::commit();

            $this->cache->invalidateContentCache($content);

            return response()->json(null, 204);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function search(SearchRequest $request): JsonResponse
    {
        $results = $this->search->search(
            $request->query,
            $request->filters ?? [],
            $request->sort ?? []
        );

        return response()->json($results);
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $field => $value) {
            $query = $this->content->applyFilter($query, $field, $value);
        }

        return $query;
    }
}
