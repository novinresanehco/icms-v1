<?php

namespace App\Core\Tagging;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TagRepository implements TagRepositoryInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    public function create(array $data): Tag
    {
        $this->validateData($data);
        
        return DB::transaction(function() use ($data) {
            $tag = Tag::create($data);
            $this->cache->tags(['tags'])->flush();
            return $tag;
        });
    }

    public function findById(int $id): ?Tag
    {
        return $this->cache->tags(['tags'])->remember(
            "tag:$id",
            3600,
            fn() => Tag::find($id)
        );
    }

    public function findByIds(array $ids): Collection
    {
        $this->validator->validateIds($ids);
        
        return $this->cache->tags(['tags'])->remember(
            'tags:' . implode(',', $ids),
            3600,
            fn() => Tag::whereIn('id', $ids)->get()
        );
    }

    public function search(string $query): Collection
    {
        $this->validator->validateSearchQuery($query);
        
        return Tag::where('name', 'like', "%{$query}%")
            ->orWhere('type', 'like', "%{$query}%")
            ->limit(100)
            ->get();
    }

    private function validateData(array $data): void
    {
        $this->validator->validate($data, [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'max:50'],
            'metadata' => ['array'],
            'user_id' => ['required', 'integer']
        ]);
    }
}
