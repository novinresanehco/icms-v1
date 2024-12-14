<?php

namespace App\Repositories;

use App\Models\Theme;
use App\Repositories\Contracts\ThemeRepositoryInterface;
use Illuminate\Support\Collection;

class ThemeRepository extends BaseRepository implements ThemeRepositoryInterface 
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['status', 'type'];

    public function __construct(Theme $model)
    {
        parent::__construct($model);
    }

    public function activate(int $themeId): bool 
    {
        try {
            DB::beginTransaction();

            $this->model->where('status', 'active')->update(['status' => 'inactive']);
            $theme = $this->find($themeId);
            $theme->update(['status' => 'active']);

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to activate theme: ' . $e->getMessage());
            return false;
        }
    }

    public function getActive(): ?Theme
    {
        try {
            return Cache::remember(
                $this->getCacheKey('active'),
                $this->cacheTTL,
                fn() => $this->model->where('status', 'active')->first()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get active theme: ' . $e->getMessage());
            return null;
        }
    }

    public function saveConfig(int $themeId, array $config): bool
    {
        try {
            DB::beginTransaction();

            $theme = $this->find($themeId);
            $theme->update(['config' => $config]);

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save theme config: ' . $e->getMessage());
            return false;
        }
    }
}
