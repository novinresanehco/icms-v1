<?php

namespace App\Core\Repository;

use App\Core\Models\Content;
use Illuminate\Support\Collection;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Validation\ValidationService;

interface SearchRepositoryInterface
{
    public function findByIds(array $ids): Collection;
    public function store(array $data): Content;
    public function clearIndex(string $id): bool;
    public function reindex(string $id): bool;
}

class SearchRepository implements SearchRepositoryInterface
{
    private Content $model;
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ValidationService $validator;

    public function __construct(
        Content $model,
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ValidationService $validator
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function findByIds(array $ids): Collection
    {
        $this->validator->validate(['ids' => $ids], [
            'ids' => 'required|array|max:1000'
        ]);

        return DB::transaction(function() use ($ids) {
            return $this->model
                ->whereIn('id', $ids)
                ->where('active', true)
                ->orderByRaw('FIELD(id,' . implode(',', $ids) . ')')
                ->limit(1000)
                ->get();
        });
    }

    public function store(array $data): Content
    {
        $this->validator->validate($data, [
            'id' => 'required|string|max:100',
            'content' => 'required|string|max:100000',
            'metadata' => 'array'
        ]);

        return DB::transaction(function() use ($data) {
            $content = $this->model->create([
                'id' => $data['id'],
                'content' => $data['content'],
                'metadata' => $data['metadata'],
                'created_by' => $this->security->getCurrentUser()->id,
                'created_at' => now()
            ]);

            $this->cache->tags(['content', "content:{$content->id}"])
                ->put($this->getCacheKey($content->id), $content, 3600);

            return $content;
        });
    }

    public function clearIndex(string $id): bool
    {
        if (!$this->security->hasPermission('index.clear')) {
            throw new UnauthorizedException();
        }

        return DB::transaction(function() use ($id) {
            $result = DB::table('search_terms')
                ->where('document_id', $id)
                ->delete();

            $this->cache->tags(['search', "content:{$id}"])->flush();

            return $result > 0;
        });
    }

    public function reindex(string $id): bool
    {
        if (!$this->security->hasPermission('index.rebuild')) {
            throw new UnauthorizedException();
        }

        return DB::transaction(function() use ($id) {
            $content = $this->model->findOrFail($id);
            
            // Clear existing index
            $this->clearIndex($id);

            // Add to search index
            event(new ContentIndexingRequested($content));

            return true;
        });
    }

    private function getCacheKey(string $id): string
    {
        return "content:{$id}";
    }
}
