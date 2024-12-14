<?php

namespace App\Core\Repositories;

use App\Models\Language;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class LanguageRepository extends AdvancedRepository
{
    protected $model = Language::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getActive(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('languages.active', function() {
                return $this->model
                    ->where('active', true)
                    ->orderBy('order')
                    ->get();
            });
        });
    }

    public function getDefault(): Language
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('language.default', function() {
                return $this->model
                    ->where('is_default', true)
                    ->firstOrFail();
            });
        });
    }

    public function setDefault(Language $language): void
    {
        $this->executeTransaction(function() use ($language) {
            $this->model->where('is_default', true)->update(['is_default' => false]);
            $language->update(['is_default' => true]);
            $this->cache->forget(['language.default', 'languages.active']);
        });
    }

    public function updateStatus(Language $language, bool $active): void
    {
        $this->executeTransaction(function() use ($language, $active) {
            $language->update(['active' => $active]);
            $this->cache->forget('languages.active');
        });
    }

    public function reorder(array $order): void
    {
        $this->executeTransaction(function() use ($order) {
            foreach ($order as $id => $position) {
                $this->model->find($id)->update(['order' => $position]);
            }
            $this->cache->forget('languages.active');
        });
    }
}
