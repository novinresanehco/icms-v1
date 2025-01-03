<?php

namespace App\Http\Controllers\Admin;

use App\Core\Content\{
    ContentManager,
    MediaManager,
    CategoryManager,
    VersionManager
};
use App\Core\Security\AccessControl;
use App\Core\Cache\CacheManager;
use App\Core\Search\SearchService;

class AdminContentController extends Controller
{
    private ContentManager $content;
    private MediaManager $media;
    private CategoryManager $categories;
    private VersionManager $versions;
    private AccessControl $access;
    private CacheManager $cache;
    private SearchService $search;

    public function store(StoreContentRequest $request): JsonResponse
    {
        $this->authorize('create', Content::class);
        
        try {
            DB::beginTransaction();

            // Create content
            $content = $this->content->create(
                $request->validated(),
                $request->user()
            );

            // Handle media
            if ($request->hasFile('media')) {
                $this->media->attachToContent(
                    $content,
                    $request->file('media')
                );
            }

            // Handle categories
            if ($request->has('categories')) {
                $this->categories->assignToContent(
                    $content,
                    $request->input('categories')
                );
            }

            // Create version
            $this->versions->createVersion($content);

            // Update search index
            $this->search->indexContent($content);

            DB::commit();

            $this->cache->invalidateContentCache($content);

            return response()->json($content, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(UpdateContentRequest $request, int $id): JsonResponse
    {
        $content = $this->content->findOrFail($id);
        $this->authorize('update', $content);

        try {
            DB::beginTransaction();

            // Create new version
            $this->versions->createVersion($content);

            // Update content
            $content = $this->content->update(
                $content,
                $request->validated()
            );

            // Update media
            if ($request->has('media')) {
                $this->media->syncWithContent(
                    $content,
                    $request->input('media')
                );
            }

            // Update categories
            if ($request->has('categories')) {
                $this->categories->syncWithContent(
                    $content,
                    $request->input('categories')
                );
            }

            // Update search index
            $this->search->updateIndex($content);

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

            // Create deletion version
            $this->versions->createDeletionVersion($content);

            // Remove from search index
            $this->search->removeFromIndex($content);

            // Delete content
            $this->content->delete($content);

            DB::commit();

            $this->cache->invalidateContentCache($content);

            return response()->json(null, 204);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function restore(int $id): JsonResponse
    {
        $content = $this->content->findTrashedOrFail($id);
        $this->authorize('restore', $content);

        try {
            DB::beginTransaction();

            // Restore content
            $this->content->restore($content);

            // Restore search index
            $this->search->indexContent($content);

            DB::commit();

            $this->cache->invalidateContentCache($content);

            return response()->json($content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function versions(int $id): JsonResponse
    {
        $content = $this->content->findOrFail($id);
        $this->authorize('viewVersions', $content);

        return response()->json(
            $this->versions->getVersionsForContent($content)
        );
    }

    public function revertToVersion(int $id, int $versionId): JsonResponse
    {
        $content = $this->content->findOrFail($id);
        $this->authorize('revertVersion', $content);

        try {
            DB::beginTransaction();

            $content = $this->versions->revertToVersion(
                $content,
                $versionId
            );

            DB::commit();

            $this->cache->invalidateContentCache($content);

            return response()->json($content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
