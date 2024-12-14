<?php

namespace App\Core\Localization\Contracts;

interface TranslationServiceInterface
{
    public function translate(string $key, array $replace = [], ?string $locale = null): string;
    public function setLocale(string $locale): void;
    public function getLocale(): string;
    public function hasTranslation(string $key, ?string $locale = null): bool;
    public function getTranslations(?string $locale = null): array;
}

interface TranslatableInterface
{
    public function translate(?string $locale = null): array;
    public function setTranslation(string $key, string $value, string $locale): void;
    public function getTranslation(string $key, ?string $locale = null): ?string;
    public function getTranslations(): array;
}

namespace App\Core\Localization\Services;

class TranslationService implements TranslationServiceInterface
{
    protected string $locale;
    protected array $loaded = [];
    protected TranslationLoader $loader;
    protected TranslationCache $cache;
    protected FallbackLocaleResolver $fallback;

    public function __construct(
        TranslationLoader $loader,
        TranslationCache $cache,
        FallbackLocaleResolver $fallback
    ) {
        $this->loader = $loader;
        $this->cache = $cache;
        $this->fallback = $fallback;
        $this->locale = config('app.locale');
    }

    public function translate(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?: $this->getLocale();
        
        $translation = $this->getTranslation($key, $locale);
        
        if ($translation === null) {
            $translation = $this->getFallbackTranslation($key, $locale);
        }
        
        return $this->replaceParameters($translation ?? $key, $replace);
    }

    public function setLocale(string $locale): void
    {
        if (!$this->isValidLocale($locale)) {
            throw new InvalidLocaleException("Invalid locale: {$locale}");
        }

        $this->locale = $locale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function hasTranslation(string $key, ?string $locale = null): bool
    {
        $locale = $locale ?: $this->getLocale();
        return $this->getTranslation($key, $locale) !== null;
    }

    public function getTranslations(?string $locale = null): array
    {
        $locale = $locale ?: $this->getLocale();
        return $this->loadTranslations($locale);
    }

    protected function getTranslation(string $key, string $locale): ?string
    {
        $translations = $this->loadTranslations($locale);
        return $translations[$key] ?? null;
    }

    protected function getFallbackTranslation(string $key, string $locale): ?string
    {
        $fallbackLocale = $this->fallback->getFallbackLocale($locale);
        
        if ($fallbackLocale) {
            return $this->getTranslation($key, $fallbackLocale);
        }

        return null;
    }

    protected function loadTranslations(string $locale): array
    {
        if (!isset($this->loaded[$locale])) {
            $this->loaded[$locale] = $this->cache->remember($locale, function () use ($locale) {
                return $this->loader->load($locale);
            });
        }

        return $this->loaded[$locale];
    }

    protected function replaceParameters(string $translation, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $translation = str_replace(":{$key}", $value, $translation);
        }

        return $translation;
    }

    protected function isValidLocale(string $locale): bool
    {
        return in_array($locale, config('app.available_locales', []));
    }
}

namespace App\Core\Localization\Services;

class TranslationManager
{
    protected LocaleManager $localeManager;
    protected TranslationLoader $loader;
    protected TranslationValidator $validator;
    protected TranslationCache $cache;

    public function __construct(
        LocaleManager $localeManager,
        TranslationLoader $loader,
        TranslationValidator $validator,
        TranslationCache $cache
    ) {
        $this->localeManager = $localeManager;
        $this->loader = $loader;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function importTranslations(array $translations, string $locale): void
    {
        // Validate translations
        $this->validator->validate($translations);

        // Import translations
        $this->loader->import($translations, $locale);

        // Clear cache for locale
        $this->cache->forget($locale);
    }

    public function exportTranslations(string $locale): array
    {
        return $this->loader->load($locale);
    }

    public function updateTranslation(string $key, string $value, string $locale): void
    {
        $this->validator->validateKey($key);
        $this->validator->validateValue($value);

        $this->loader->update($key, $value, $locale);
        $this->cache->forget($locale);
    }

    public function deleteTranslation(string $key, string $locale): void
    {
        $this->loader->delete($key, $locale);
        $this->cache->forget($locale);
    }
}

namespace App\Core\Localization\Models;

trait Translatable
{
    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    public function translate(?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();
        
        return $this->translations
            ->where('locale', $locale)
            ->pluck('value', 'key')
            ->toArray();
    }

    public function setTranslation(string $key, string $value, string $locale): void
    {
        $this->translations()->updateOrCreate(
            ['key' => $key, 'locale' => $locale],
            ['value' => $value]
        );
    }

    public function getTranslation(string $key, ?string $locale = null): ?string
    {
        $locale = $locale ?: app()->getLocale();
        
        $translation = $this->translations
            ->where('key', $key)
            ->where('locale', $locale)
            ->first();

        return $translation ? $translation->value : null;
    }

    public function getTranslations(): array
    {
        return $this->translations
            ->groupBy('locale')
            ->map(function ($items) {
                return $items->pluck('value', 'key');
            })
            ->toArray();
    }
}

class Translation extends Model
{
    protected $fillable = [
        'key',
        'value',
        'locale',
        'translatable_type',
        'translatable_id'
    ];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}

namespace App\Core\Localization\Services;

class LocaleManager
{
    protected array $locales;
    protected array $fallbacks;

    public function __construct(array $config)
    {
        $this->locales = $config['locales'];
        $this->fallbacks = $config['fallbacks'];
    }

    public function getAvailableLocales(): array
    {
        return $this->locales;
    }

    public function isValidLocale(string $locale): bool
    {
        return in_array($locale, $this->locales);
    }

    public function getFallbackLocale(string $locale): ?string
    {
        return $this->fallbacks[$locale] ?? null;
    }

    public function getDefaultLocale(): string
    {
        return config('app.locale');
    }

    public function getSupportedLocales(): array
    {
        return array_map(function ($locale) {
            return [
                'code' => $locale,
                'name' => $this->getLocaleName($locale),
                'native' => $this->getLocaleNativeName($locale),
                'fallback' => $this->getFallbackLocale($locale)
            ];
        }, $this->locales);
    }
}
