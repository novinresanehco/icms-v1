<?php

namespace App\Core\Tag\Console\Commands;

use Illuminate\Console\Command;
use App\Core\Tag\Services\TagService;
use App\Core\Tag\Services\TagCacheService;

class CleanupTagsCommand extends Command
{
    protected $signature = 'tags:cleanup {--force : Force cleanup without confirmation}';
    protected $description = 'Clean up unused tags and optimize tag relationships';

    public function handle(TagService $tagService): int
    {
        if (!$this->option('force') && !$this->confirm('This will remove unused tags. Continue?')) {
            return 1;
        }

        $count = $tagService->cleanupUnusedTags();
        $this->info("Removed {$count} unused tags.");

        return 0;
    }
}

class RebuildTagCacheCommand extends Command
{
    protected $signature = 'tags:cache-rebuild';
    protected $description = 'Rebuild tag cache';

    public function handle(TagCacheService $cacheService): int
    {
        $this->info('Rebuilding tag cache...');
        
        $cacheService->invalidateAllTags();
        $cacheService->warmCache();
        
        $this->info('Tag cache rebuilt successfully.');
        return 0;
    }
}

class MergeTagsCommand extends Command
{
    protected $signature = 'tags:merge {source : Source tag ID} {target : Target tag ID}';
    protected $description = 'Merge two tags together';

    public function handle(TagService $tagService): int
    {
        $sourceId = $this->argument('source');
        $targetId = $this->argument('target');

        $this->info("Merging tag {$sourceId} into {$targetId}...");

        try {
            $tagService->mergeTags($sourceId, $targetId);
            $this->info('Tags merged successfully.');
            return 0;
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
