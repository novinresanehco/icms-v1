<?php

namespace App\Core\Repository;

use App\Models\Translation;
use App\Core\Events\TranslationEvents;
use App\Core\Exceptions\TranslationRepositoryException;

class TranslationRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Translation::class;
    }

    /**
     * Set translation
     */
    public function setTranslation(string $key, array $translations): Translation
    {
        try {
            DB::beginTransaction();

            $translation = $this->model->updateOrCreate(
                ['key' => $key],
                [
                    'translations' => $translations,
                    'last_updated' => now(),
                    'updated_by' => auth()->id()
                ]
            );

            DB::commit();
            $this->clearCache();
            event(new TranslationEvents\TranslationUpdated($translation));

            return $translation;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TranslationRepositoryException(
                "Failed to set translation: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get translations for locale
     */
    public function getTranslationsForLocale(string $locale): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("locale.{$locale}"),
            $this->cacheTime,
            function() use ($locale) {
                $translations = [];
                $records = $this->model->all();

                foreach ($records as $record) {
                    if (isset($record->translations[$locale])) {
                        $translations[$record->key] = $record->translations[$locale];
                    }
                }

                return $translations;
            }
        );
    }

    /**
     * Get missing translations
     */
    public function getMissingTranslations(string $locale): Collection
    {
        return $this->model
            ->whereRaw("NOT JSON_CONTAINS_PATH(translations, 'one', ?\$)", ["$.{$locale}"])
            ->get();
    }

    /**
     * Import translations
     */
    public function importTranslations(array $translations, bool $overwrite = false): int
    {
        try {
            DB::beginTransaction();
            $count = 0;

            foreach ($translations as $key => $data) {
                if ($overwrite) {
                    $translation = $this->setTranslation($key, $data);
                    $count++;
                } else {
                    $existing = $this->model->where('key', $key)->first();
                    if (!$existing) {
                        $translation = $this->setTranslation($key, $data);
                        $count++;
                    }
                }
            }

            DB::commit();
            $this->clearCache();
            event(new TranslationEvents\TranslationsImported($count));

            return $count;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TranslationRepositoryException(
                "Failed to import translations: {$e->getMessage()}"
            );
        }
    }

    /**
     * Export translations
     */
    public function exportTranslations(array $locales = []): array
    {
        try {
            $query = $this->model->newQuery();

            if (!empty($locales)) {
                $query->whereRaw(
                    'JSON_OVERLAPS(JSON_KEYS(translations), CAST(? AS JSON))',
                    [json_encode($locales)]
                );
            }

            $translations = [];
            foreach ($query->get() as $translation) {
                if (empty($locales)) {
                    $translations[$translation->key] = $translation->translations;
                } else {
                    $translations[$translation->key] = array_intersect_key(
                        $translation->translations,
                        array_flip($locales)
                    );
                }
            }

            return $translations;

        } catch (\Exception $e) {
            throw new TranslationRepositoryException(
                "Failed to export translations: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get translation statistics
     */
    public function getStatistics(): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('stats'),
            $this->cacheTime,
            function() {
                $stats = ['total' => $this->model->count()];
                $records = $this->model->get();

                foreach ($records as $record) {
                    foreach ($record->translations as $locale => $translation) {
                        $stats['locales'][$locale] = ($stats['locales'][$locale] ?? 0) + 1;
                    }
                }

                return $stats;
            }
        );
    }
}
