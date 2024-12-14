// File: app/Core/I18n/Manager/TranslationManager.php
<?php

namespace App\Core\I18n\Manager;

class TranslationManager
{
    protected TranslationLoader $loader;
    protected TranslationCache $cache;
    protected LocaleManager $localeManager;
    protected FallbackResolver $fallbackResolver;

    public function translate(string $key, array $replacements = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->localeManager->getCurrentLocale();
        $cacheKey = $this->buildCacheKey($key, $locale);

        return $this->cache->remember($cacheKey, function() use ($key, $locale) {
            return $this->findTranslation($key, $locale);
        });
    }

    public function setLocale(string $locale): void
    {
        if (!$this->localeManager->isValidLocale($locale)) {
            throw new InvalidLocaleException("Invalid locale: {$locale}");
        }

        $this->localeManager->setCurrentLocale($locale);
    }

    protected function findTranslation(string $key, string $locale): string
    {
        $translation = $this->loader->load($key, $locale);
        
        if (!$translation && ($fallback = $this->fallbackResolver->getFallback($locale))) {
            $translation = $this->findTranslation($key, $fallback);
        }

        return $translation;
    }
}

// File: app/Core/I18n/Loader/TranslationLoader.php
<?php

namespace App\Core\I18n\Loader;

class TranslationLoader
{
    protected FileLoader $fileLoader;
    protected DatabaseLoader $databaseLoader;
    protected LoaderConfig $config;

    public function load(string $key, string $locale): ?string
    {
        // Try database first
        if ($translation = $this->databaseLoader->load($key, $locale)) {
            return $translation;
        }

        // Fall back to file system
        return $this->fileLoader->load($key, $locale);
    }

    public function addTranslation(string $key, string $value, string $locale): void
    {
        $this->databaseLoader->save($key, $value, $locale);
        $this->cache->forget($this->buildCacheKey($key, $locale));
    }
}

// File: app/Core/I18n/Locale/LocaleManager.php
<?php

namespace App\Core\I18n\Locale;

class LocaleManager
{
    protected string $currentLocale;
    protected array $availableLocales = [];
    protected LocaleConfig $config;

    public function setCurrentLocale(string $locale): void
    {
        if (!$this->isValidLocale($locale)) {
            throw new InvalidLocaleException("Invalid locale: {$locale}");
        }

        $this->currentLocale = $locale;
    }

    public function getCurrentLocale(): string
    {
        return $this->currentLocale ?? $this->config->getDefaultLocale();
    }

    public function isValidLocale(string $locale): bool
    {
        return in_array($locale, $this->availableLocales);
    }

    public function getAvailableLocales(): array
    {
        return $this->availableLocales;
    }
}

// File: app/Core/I18n/Format/FormatManager.php
<?php

namespace App\Core\I18n\Format;

class FormatManager
{
    protected DateFormatter $dateFormatter;
    protected NumberFormatter $numberFormatter;
    protected CurrencyFormatter $currencyFormatter;
    protected FormatConfig $config;

    public function formatDate(DateTime $date, string $format = null, string $locale = null): string
    {
        $locale = $locale ?? $this->localeManager->getCurrentLocale();
        return $this->dateFormatter->format($date, $format, $locale);
    }

    public function formatNumber($number, array $options = [], string $locale = null): string
    {
        $locale = $locale ?? $this->localeManager->getCurrentLocale();
        return $this->numberFormatter->format($number, $options, $locale);
    }

    public function formatCurrency($amount, string $currency, array $options = [], string $locale = null): string
    {
        $locale = $locale ?? $this->localeManager->getCurrentLocale();
        return $this->currencyFormatter->format($amount, $currency, $options, $locale);
    }
}
