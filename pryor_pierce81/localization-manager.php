<?php

namespace App\Core\Localization;

class LocalizationManager
{
    private TranslationRepository $repository;
    private CacheManager $cache;
    private string $defaultLocale;
    private string $fallbackLocale;
    private array $loadedTranslations = [];

    public function translate(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->defaultLocale;
        $this->loadTranslationsForLocale($locale);

        $translation = $this->loadedTranslations[$locale][$key] 
            ?? $this->loadedTranslations[$this->fallbackLocale][$key] 
            ?? $key;

        return $this->replaceParameters($translation, $replace);
    }

    public function setTranslation(string $key, string $text, string $locale): void
    {
        $this->repository->save(new Translation($key, $text, $locale));
        $this->cache->forget("translations.$locale");
        unset($this->loadedTranslations[$locale]);
    }

    public function importTranslations(string $locale, array $translations): void
    {
        foreach ($translations as $key => $text) {
            $this->setTranslation($key, $text, $locale);
        }
    }

    private function loadTranslationsForLocale(string $locale): void
    {
        if (!isset($this->loadedTranslations[$locale])) {
            $this->loadedTranslations[$locale] = $this->cache->remember(
                "translations.$locale",
                fn() => $this->repository->getAllForLocale($locale)
            );
        }
    }

    private function replaceParameters(string $text, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $text = str_replace(":$key", $value, $text);
        }
        return $text;
    }
}

class TranslationRepository
{
    private $connection;

    public function save(Translation $translation): void
    {
        $this->connection->table('translations')->updateOrInsert(
            [
                'key' => $translation->getKey(),
                'locale' => $translation->getLocale()
            ],
            [
                'text' => $translation->getText(),
                'updated_at' => now()
            ]
        );
    }

    public function getAllForLocale(string $locale): array
    {
        return $this->connection->table('translations')
            ->where('locale', $locale)
            ->pluck('text', 'key')
            ->toArray();
    }

    public function findByKey(string $key, string $locale): ?Translation
    {
        $row = $this->connection->table('translations')
            ->where('key', $key)
            ->where('locale', $locale)
            ->first();

        return $row ? new Translation($row->key, $row->text, $row->locale) : null;
    }
}

class Translation
{
    private string $key;
    private string $text;
    private string $locale;
    private array $metadata;
    private \DateTime $updatedAt;

    public function __construct(
        string $key,
        string $text,
        string $locale,
        array $metadata = []
    ) {
        $this->key = $key;
        $this->text = $text;
        $this->locale = $locale;
        $this->metadata = $metadata;
        $this->updatedAt = new \DateTime();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }
}

class LocaleManager
{
    private array $availableLocales = [];
    private array $localeMetadata = [];

    public function addLocale(string $locale, array $metadata = []): void
    {
        $this->availableLocales[] = $locale;
        $this->localeMetadata[$locale] = $metadata;
    }

    public function removeLocale(string $locale): void
    {
        $key = array_search($locale, $this->availableLocales);
        if ($key !== false) {
            unset($this->availableLocales[$key]);
            unset($this->localeMetadata[$locale]);
        }
    }

    public function getAvailableLocales(): array
    {
        return $this->availableLocales;
    }

    public function isLocaleAvailable(string $locale): bool
    {
        return in_array($locale, $this->availableLocales);
    }

    public function getLocaleMetadata(string $locale): array
    {
        return $this->localeMetadata[$locale] ?? [];
    }
}

class TranslationExporter
{
    private TranslationRepository $repository;

    public function export(string $locale): array
    {
        return $this->repository->getAllForLocale($locale);
    }

    public function exportToFile(string $locale, string $format = 'json'): string
    {
        $translations = $this->export($locale);

        switch ($format) {
            case 'json':
                return json_encode($translations, JSON_PRETTY_PRINT);
            case 'php':
                return '<?php return ' . var_export($translations, true) . ';';
            default:
                throw new \InvalidArgumentException("Unsupported format: $format");
        }
    }
}

class TranslationValidator
{
    public function validate(string $text, array $rules = []): bool
    {
        foreach ($rules as $rule) {
            if (!$this->evaluateRule($rule, $text)) {
                return false;
            }
        }
        return true;
    }

    private function evaluateRule(string $rule, string $text): bool
    {
        switch ($rule) {
            case 'not_empty':
                return !empty($text);
            case 'no_html':
                return strip_tags($text) === $text;
            case 'valid_placeholders':
                return $this->validatePlaceholders($text);
            default:
                return true;
        }
    }

    private function validatePlaceholders(string $text): bool
    {
        preg_match_all('/:([a-zA-Z_]+)/', $text, $matches);
        return !empty($matches[1]) && count(array_unique($matches[1])) === count($matches[1]);
    }
}
