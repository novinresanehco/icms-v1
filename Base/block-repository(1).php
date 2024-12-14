<?php

namespace App\Core\Repositories;

use App\Models\Block;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class BlockRepository extends AdvancedRepository
{
    protected $model = Block::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getForRegion(string $region): Collection
    {
        return $this->executeQuery(function() use ($region) {
            return $this->cache->remember("blocks.region.{$region}", function() use ($region) {
                return $this->model
                    ->where('region', $region)
                    ->where('active', true)
                    ->orderBy('weight')
                    ->get();
            });
        });
    }

    public function updateBlockContent(Block $block, array $content): void
    {
        $this->executeTransaction(function() use ($block, $content) {
            $block->update([
                'content' => $content,
                'updated_at' => now()
            ]);
            
            $this->cache->tags('blocks')->flush();
        });
    }

    public function updateVisibility(Block $block, array $visibility): void
    {
        $this->executeTransaction(function() use ($block, $visibility) {
            $block->update([
                'visibility_rules' => $visibility,
                'updated_at' => now()
            ]);
            
            $this->cache->tags('blocks')->flush();
        });
    }

    public function reorder(array $weights): void
    {
        $this->executeTransaction(function() use ($weights) {
            foreach ($weights as $id => $weight) {
                $this->model->where('id', $id)->update(['weight' => $weight]);
            }
            
            $this->cache->tags('blocks')->flush();
        });
    }
}
