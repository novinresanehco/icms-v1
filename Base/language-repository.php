<?php

namespace App\Repositories;

use App\Models\Language;
use App\Repositories\Contracts\LanguageRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LanguageRepository extends BaseRepository implements LanguageRepositoryInterface
{
    protected array $searchableFields = ['name', 'code', 'locale'];
    protected array $filterableFields = ['status', 'is_rtl'];

    public function getActive(): Collection
    {
        return Cache::tags(['languages'])->remember('languages.active', 3600, function() {
            return $this->model
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
        });
    }

    public function findByLocale(string $locale): ?Language
    {
        return Cache::tags(['languages'])->remember("language.{$locale}", 3600, function() use ($locale) {
            return $this->model
                ->where('locale', $locale)
                ->first();
        });
    }

    public function setDefault(int $id): bool
    {
        try {
            $this->model->where('is_default', true)->update(['is_default' => false]);
            $language = $this->findById($id);
            $language->is_default = true;
            $language->save();
            
            Cache::tags(['languages'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error setting default language: ' . $e->getMessage());
            return false;
        }
    }

    public function syncTranslations(int $id, array $translations): bool
    {
        try {
            $language = $this->findById($id);
            foreach ($translations as $key => $value) {
                $language->translations()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value]
                );
            }
            
            Cache::tags(['translations'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error syncing translations: ' . $e->getMessage());
            return false;
        }
    }

    public function getTranslations(int $id): array
    {
        return Cache::tags(['translations'])->remember("translations.{$id}", 3600, function() use ($id) {
            $language = $this->findById($id);
            return $language->translations()
                ->pluck('value', 'key')
                ->toArray();
        });
    }
}
