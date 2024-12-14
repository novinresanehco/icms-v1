<?php

namespace App\Repositories;

use App\Models\Translation;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class TranslationRepository extends BaseRepository
{
    public function __construct(Translation $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findForModel(Model $model, string $locale): ?Translation
    {
        return $this->executeWithCache(__FUNCTION__, [get_class($model), $model->getKey(), $locale], function () use ($model, $locale) {
            return $this->model->where('translatable_type', get_class($model))
                             ->where('translatable_id', $model->getKey())
                             ->where('locale', $locale)
                             ->first();
        });
    }

    public function updateOrCreate(Model $model, string $locale, array $attributes): Translation
    {
        $translation = $this->model->updateOrCreate(
            [
                'translatable_type' => get_class($model),
                'translatable_id' => $model->getKey(),
                'locale' => $locale
            ],
            $attributes
        );

        $this->clearCache();
        return $translation;
    }

    public function deleteForModel(Model $model, ?string $locale = null): int
    {
        $query = $this->model->where('translatable_type', get_class($model))
                            ->where('translatable_id', $model->getKey());

        if ($locale) {
            $query->where('locale', $locale);
        }

        $count = $query->delete();
        $this->clearCache();
        return $count;
    }

    public function findMissing(string $locale): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$locale], function () use ($locale) {
            return $this->model->whereNull('value')
                             ->where('locale', $locale)
                             ->get();
        });
    }

    public function import(string $locale, array $translations): int
    {
        $count = 0;
        foreach ($translations as $key => $value) {
            $this->model->updateOrCreate(
                ['key' => $key, 'locale' => $locale],
                ['value' => $value]
            );
            $count++;
        }
        
        $this->clearCache();
        return $count;
    }
}
