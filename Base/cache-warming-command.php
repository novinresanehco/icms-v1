<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Core\Services\Cache\RepositoryCacheWarmer;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Helper\ProgressBar;

class WarmRepositoryCacheCommand extends Command
{
    protected $signature = 'repository:warm-cache 
                          {--force : Force cache warming even if already warmed}
                          {--entity= : Warm cache for specific entity type}';

    protected $description = 'Warm repository caches for better performance';

    protected RepositoryCacheWarmer $cacheWarmer;

    public function __construct(RepositoryCacheWarmer $cacheWarmer)
    {
        parent::__construct();
        $this->cacheWarmer = $cacheWarmer;
    }

    public function handle(): int
    {
        $startTime = microtime(true);

        $this->info('Starting repository cache warming...');

        try {
            if ($this->option('entity')) {
                $this->warmSpecificEntity($this->option('entity'));
            } else {
                $this->warmAllCaches();
            }

            $duration = round(microtime(true) - $startTime, 2);
            $this->info("Cache warming completed in {$duration} seconds");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Cache warming failed: {$e->getMessage()}");
            Log::error('Cache warming failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    protected function warmAllCaches(): void
    {
        $entities = config('repository.cache.warming.entities');
        $progress = $this->output->createProgressBar(count($entities));
        
        $this->info('Warming all repository caches...');
        $progress->start();

        foreach ($entities as $entity => $types) {
            $this->warmEntityCache($entity, $types);
            $progress->advance();
        }

        $progress->finish();
        $this->newLine();
    }

    protected function warmEntityCache(string $entity, array $types): void
    {
        $this->line("Warming {$entity} cache...");
        
        foreach ($types as $type) {
            $method = "warm{$entity}Cache";
            if (method_exists($this->cacheWarmer, $method)) {
                $this->cacheWarmer->$method($type);
            }
        }
    }

    protected function warmSpecificEntity(string $entity): void
    {
        $entities = config('repository.cache.warming.entities');
        
        if (!isset($entities[$entity])) {
            throw new \InvalidArgumentException("Invalid entity type: {$entity}");
        }

        $this->info("Warming cache for {$entity}...");
        $this->warmEntityCache($entity, $entities[$entity]);
    }
}
