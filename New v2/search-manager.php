<?php

namespace App\Core\Search;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Search\Index\SearchIndexInterface;
use Illuminate\Support\Facades\DB;

class SearchManager implements SearchManagerInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private SearchIndexInterface $index;
    private SearchAnalyzerInterface $analyzer;
    private array $searchFilters;
    
    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        SearchIndexInterface $index,
        SearchAnalyzerInterface $analyzer,
        array $searchFilters
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->index = $index;
        $this->analyzer = $analyzer;
        $this->searchFilters = $searchFilters;
    }

    public function index(IndexRequest $request): void
    {
        DB::beginTransaction();
        
        try {
            $this->validateIndexRequest($request);
            $this->verifyIndexPermissions($request);

            $terms = $this->analyzer->analyze($request->content);
            $this->index->indexDocument($request->id, $terms, $request->metadata);

            DB::commit();
            
            $this->cache->invalidate($this->getIndexCacheKey($request->id));
            
            $this->logIndexSuccess($request);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logIndexFailure($request, $e);
            throw $e;
        }
    }

    public function delete(DeleteRequest $request): void
    {
        DB::beginTransaction();
        
        try {
            $this->verifyDeletePermissions($request);
            $this->index->deleteDocument($request->id);
            
            DB::commit();
            
            $this->cache->invalidate($this->getIndexCacheKey($request->id));
            
            $this->logDeleteSuccess($request);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logDeleteFailure($request, $e);
            throw $e;
        }
    }

    public function update(UpdateRequest $request): void
    {
        DB::beginTransaction();
        
        try {
            $this->validateUpdateRequest($request);
            $this->verifyUpdatePermissions($request);

            $terms = $this->analyzer->analyze($request->content);
            $this->index->updateDocument($request->id, $terms, $request->metadata);
            
            DB::commit();
            
            $this->cache->invalidate($this->getIndexCacheKey($request->id));
            
            $this->logUpdateSuccess($request);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->logUpdateFailure($request, $e);
            throw $e;
        }
    }

    public function optimize(): void
    {
        try {
            $this->verifyOptimizePermissions();
            $this->index->optimize();
            $this->logOptimizeSuccess();
            
        } catch (\Exception $e) {
            $this->logOptimizeFailure($e);
            throw $e;
        }
    }

    private function validateIndexRequest(IndexRequest $request): void
    {
        if (empty($request->content)) {
            throw new ValidationException('Index content cannot be empty');
        }

        if (empty($request->id)) {
            throw new ValidationException('Document ID is required');
        }
    }

    private function validateUpdateRequest(UpdateRequest $request): void
    {
        if (empty($request->content)) {
            throw new ValidationException('Update content cannot be empty');
        }

        if (empty($request->id)) {
            throw new ValidationException('Document ID is required');
        }
    }

    private function verifyIndexPermissions(IndexRequest $request): void
    {
        if (!$this->security->hasPermission($request->user, 'search.index')) {
            throw new UnauthorizedException('Unauthorized to index documents');
        }
    }

    private function verifyUpdatePermissions(UpdateRequest $request): void
    {
        if (!$this->security->hasPermission($request->user, 'search.update')) {
            throw new UnauthorizedException('Unauthorized to update documents');
        }
    }

    private function verifyDeletePermissions(DeleteRequest $request): void
    {
        if (!$this->security->hasPermission($request->user, 'search.delete')) {
            throw new UnauthorizedException('Unauthorized to delete documents');
        }
    }

    private function verifyOptimizePermissions(): void
    {
        if (!$this->security->hasPermission(auth()->user(), 'search.optimize')) {
            throw new UnauthorizedException('Unauthorized to optimize search index');
        }
    }

    private function getIndexCacheKey(string $id): string
    {
        return "search_index:{$id}";
    }

    private function logIndexSuccess(IndexRequest $request): void
    {
        Log::info('Document indexed successfully', [
            'id' => $request->id,
            'user' => $request->user->id,
            'type' => $request->metadata['type'] ?? null
        ]);
    }

    private function logIndexFailure(IndexRequest $request, \Exception $e): void
    {
        Log::error('Document indexing failed', [
            'id' => $request->id,
            'user' => $request->user->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function logUpdateSuccess(UpdateRequest $request): void
    {
        Log::info('Document updated successfully', [
            'id' => $request->id,
            'user' => $request->user->id
        ]);
    }

    private function logUpdateFailure(UpdateRequest $request, \Exception $e): void
    {
        Log::error('Document update failed', [
            'id' => $request->id,
            'user' => $request->user->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function logDeleteSuccess(DeleteRequest $request): void
    {
        Log::info('Document deleted successfully', [
            'id' => $request->id,
            'user' => $request->user->id
        ]);
    }

    private function logDeleteFailure(DeleteRequest $request, \Exception $e): void
    {
        Log::error('Document deletion failed', [
            'id' => $request->id,
            'user' => $request->user->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function logOptimizeSuccess(): void
    {
        Log::info('Search index optimized successfully', [
            'user' => auth()->id()
        ]);
    }

    private function logOptimizeFailure(\Exception $e): void
    {
        Log::error('Search index optimization failed', [
            'user' => auth()->id(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
