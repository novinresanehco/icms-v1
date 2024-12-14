<?php

namespace App\Core\Repositories;

use App\Models\Redirect;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class RedirectRepository extends AdvancedRepository
{
    protected $model = Redirect::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function findBySource(string $source): ?Redirect
    {
        return $this->executeQuery(function() use ($source) {
            return $this->cache->remember("redirect.source.{$source}", function() use ($source) {
                return $this->model
                    ->where('source_url', $source)
                    ->where('active', true)
                    ->first();
            });
        });
    }

    public function createRedirect(string $source, string $destination, int $type = 301): Redirect
    {
        return $this->executeTransaction(function() use ($source, $destination, $type) {
            $redirect = $this->create([
                'source_url' => $source,
                'destination_url' => $destination,
                'type' => $type,
                'active' => true,
                'created_at' => now()
            ]);
            
            $this->cache->forget("redirect.source.{$source}");
            return $redirect;
        });
    }

    public function updateDestination(Redirect $redirect, string $destination): void
    {
        $this->executeTransaction(function() use ($redirect, $destination) {
            $redirect->update([
                'destination_url' => $destination,
                'updated_at' => now()
            ]);
            
            $this->cache->forget("redirect.source.{$redirect->source_url}");
        });
    }

    public function logRedirect(Redirect $redirect): void
    {
        $this->executeTransaction(function() use ($redirect) {
            $redirect->increment('hits');
            $redirect->update(['last_accessed_at' => now()]);
        });
    }

    public function getBrokenRedirects(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model
                ->where('active', true)
                ->where('last_checked_at', '<=', now()->subDays(1))
                ->orWhereNull('last_checked_at')
                ->get();
        });
    }
}
