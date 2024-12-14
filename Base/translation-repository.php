<?php

namespace App\Repositories;

use App\Models\Translation;
use App\Repositories\Contracts\TranslationRepositoryInterface;
use Illuminate\Support\Collection;

class TranslationRepository extends BaseRepository implements TranslationRepositoryInterface
{
    protected array $searchableFields = ['key', 'value', 'group'];
    protected array $filterableFields = ['locale', 'namespace'];

    public function getByLocale(string $locale): Collection
    {
        return Cache::remember(
            $this->getCacheKey("locale.{$locale}"),
            $this->cacheTTL,
            fn() => $this->model->where('locale', $locale)->get()
        );
    }

    public function importTranslations(string $locale, array $translations): bool
    {
        try {
            DB::beginTransaction();
            
            foreach ($translations as $group => $items) {
                foreach ($items as $key => $value) {
                    $this->model->updateOrCreate(
                        ['locale' => $locale, 'group' => $group, 'key' => $key],
                        ['value' => $value]
                    );
                }
            }
            
            DB::commit();
            $this->clearModelCache();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    public function exportTranslations(string $locale): array
    {
        $translations = $this->getByLocale($locale);
        return $translations->groupBy('group')->map(function ($items) {
            return $items->pluck('value', 'key');
        })->toArray();
    }

    public function getMissing(string $locale): Collection
    {
        $defaultLocale = app(LanguageRepository::class)->getDefault()->locale;
        
        return $this->model->where('locale', $defaultLocale)
            ->whereNotExists(function ($query) use ($locale) {
                $query->select(DB::raw(1))
                    ->from('translations as t')
                    ->whereRaw('t.key = translations.key')
                    ->where('locale', $locale);
            })
            ->get();
    }
}
