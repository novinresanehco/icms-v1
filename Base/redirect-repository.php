<?php

namespace App\Repositories;

use App\Models\Redirect;
use App\Repositories\Contracts\RedirectRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class RedirectRepository extends BaseRepository implements RedirectRepositoryInterface
{
    protected array $searchableFields = ['from_url', 'to_url', 'notes'];
    protected array $filterableFields = ['status', 'type', 'code'];

    public function findRedirect(string $fromUrl): ?Redirect
    {
        return Cache::tags(['redirects'])->remember("redirect.{$fromUrl}", 3600, function() use ($fromUrl) {
            return $this->model
                ->where('from_url', $fromUrl)
                ->where('status', 'active')
                ->first();
        });
    }

    public function createRedirect(string $fromUrl, string $toUrl, int $code = 301, string $notes = ''): Redirect
    {
        $redirect = $this->create([
            'from_url' => $fromUrl,
            'to_url' => $toUrl,
            'code' => $code,
            'notes' => $notes,
            'status' => 'active',
            'hits' => 0
        ]);

        Cache::tags(['redirects'])->forget("redirect.{$fromUrl}");
        return $redirect;
    }

    public function incrementHits(int $id): bool
    {
        try {
            $redirect = $this->findById($id);
            $redirect->increment('hits');
            $redirect->last_hit_at = now();
            $redirect->save();
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error incrementing redirect hits: ' . $e->getMessage());
            return false;
        }
    }

    public function bulkImport(array $redirects): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($redirects as $redirect) {
            try {
                $this->createRedirect(
                    $redirect['from_url'],
                    $redirect['to_url'],
                    $redirect['code'] ?? 301,
                    $redirect['notes'] ?? ''
                );
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'from_url' => $redirect['from_url'],
                    'error' => $e->getMessage()
                ];
            }
        }

        Cache::tags(['redirects'])->flush();
        return $results;
    }
}
