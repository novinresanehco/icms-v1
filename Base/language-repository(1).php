<?php

namespace App\Repositories;

use App\Models\Language;
use App\Repositories\Contracts\LanguageRepositoryInterface;
use Illuminate\Support\Collection;

class LanguageRepository extends BaseRepository implements LanguageRepositoryInterface
{
    protected array $searchableFields = ['name', 'code', 'locale'];
    protected array $filterableFields = ['status', 'direction'];

    public function setDefault(int $languageId): bool
    {
        try {
            DB::beginTransaction();
            
            $this->model->where('is_default', true)->update(['is_default' => false]);
            $language = $this->find($languageId);
            $language->update(['is_default' => true]);
            
            DB::commit();
            $this->clearModelCache();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    public function getActive(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('active'),
            $this->cacheTTL,
            fn() => $this->model->where('status', 'active')->get()
        );
    }

    public function getDefault(): ?Language
    {
        return Cache::remember(
            $this->getCacheKey('default'),
            $this->cacheTTL,
            fn() => $this->model->where('is_default', true)->first()
        );
    }

    public function syncTranslations(array $translations): bool
    {
        try {
            DB::beginTransaction();
            
            foreach ($translations as $locale => $items) {
                $language = $this->model->where('locale', $locale)->first();
                if ($language) {
                    $language->translations()->sync($items);
                }
            }
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }
}
