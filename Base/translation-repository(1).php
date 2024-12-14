<?php

namespace App\Core\Repositories;

use App\Models\Translation;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class TranslationRepository extends AdvancedRepository
{
    protected $model = Translation::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getForModel($model, string $locale): Collection
    {
        return $this->executeQuery(function() use ($model, $locale) {
            return $this->cache->remember(
                "translations.{$model->getMorphClass()}.{$model->id}.{$locale}",
                function() use ($model, $locale) {
                    return $this->model
                        ->where('translatable_type', get_class($model))
                        ->where('translatable_id', $model->id)
                        ->where('locale', $locale)
                        ->get();
                }
            );
        });
    }

    public function updateOrCreate($model, string $locale, array $translations): void
    {
        $this->executeTransaction(function() use ($model, $locale, $translations) {
            foreach ($translations as $field => $value) {
                $this->model->updateOrCreate(
                    [
                        'translatable_type' => get_class($model),
                        'translatable_id' => $model->id,
                        'locale' => $locale,
                        'field' => $field
                    ],
                    ['value' => $value]
                );
            }
            
            $this->cache->forget("translations.{$model->getMorphClass()}.{$model->id}.{$locale}");
        });
    }

    public function deleteForLocale($model, string $locale): void
    {
        $this->executeTransaction(function() use ($model, $locale) {
            $this->model
                ->where('translatable_type', get_class($model))
                ->where('translatable_id', $model->id)
                ->where('locale', $locale)
                ->delete();
                
            $this->cache->forget("translations.{$model->getMorphClass()}.{$model->id}.{$locale}");
        });
    }

    public function getCachedTranslations(string $locale): array
    {
        return $this->cache->remember("translations.{$locale}", function() use ($locale) {
            $translations = [];
            $results = $this->model->where('locale', $locale)->get();
            
            foreach ($results as $translation) {
                $translations[$translation->group][$translation->key] = $translation->value;
            }
            
            return $translations;
        });
    }
}
