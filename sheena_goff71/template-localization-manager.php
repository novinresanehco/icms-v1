<?php

namespace App\Core\Template\Localization;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Core\Template\Exceptions\LocalizationException;

class LocalizationManager
{
    private Collection $translations;
    private array $config;
    private string $defaultLocale;
    private string $currentLocale;
    private LocaleLoader $loader;
    private TranslationCache $cache;

    public function __construct(LocaleLoader $loader, TranslationCache $cache, array $config = [])
    {
        $this->loader = $loader;
        $this->cache = $cache;
        $this->translations = new Collection();
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->defaultLocale = $this->config['default_locale'];
        $this->currentLocale = $this->defaultLocale;
    }

    /**
     * Set current locale
     *
     * @param string $locale
     * @return bool
     */
    public function setLocale(string $locale): bool
    {
        if (!$this->isValidLocale($locale)) {
            throw new LocalizationException("Invalid locale: {$locale}");
        }

        $this->currentLocale = $locale;
        $this->loadTranslations($locale);
        
        return true;
    }

    /**
     * Get translation for key
     *
     * @param string $key
     * @param array $replace
     * @param string|null $locale
     * @return string
     */
    public function translate(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->currentLocale;
        $translation = $this->getTranslation($key, $locale);

        if ($translation === null) {
            if ($this->config['fallback_to_default']) {
                $translation = $this->getTranslation($key, $this->defaultLocale);
            }
            
            if ($translation === null) {
                return $this->config['return_key_if_missing'] ? $key : '';
            }
        }

        return $this->replacePlaceholders($translation, $replace);
    }

    /**
     * Get all supported locales
     *
     * @return array
     */
    public function getSupportedLocales(): array
    {
        return $this->config['supported_locales'];
    }

    /**
     * Check if locale is supported
     *
     * @param string $locale
     * @return bool
     */
    public function isValidLocale(string $locale): bool
    {
        return in_array($locale, $this->getSupportedLocales());
    }

    /**
     * Add translation
     *
     * @param string $locale
     * @param string $key
     * @param string $value
     * @return void
     */
    public function addTranslation(string $locale, string $key, string $value): void
    {
        if (!$this->translations->has($locale)) {
            $this->translations[$locale] = new Collection();
        }

        $this->translations[$locale]->put($key, $value);
        $this->cache->store($locale, $key, $value);
    }

    /**
     * Export translations to file
     *
     * @param string $locale
     * @param string $format
     * @return string
     */
    public function export(string $locale, string $format = 'json'): string
    {
        $translations = $this->translations->get($locale, new Collection());

        switch ($format) {
            case 'json':
                return json_encode($translations->all(), JSON_PRETTY_PRINT);
            case 'php':
                return "<?php\nreturn " . var_export($translations->all(), true) . ';';
            default:
                throw new LocalizationException("Unsupported export format: {$format}");
        }
    }

    /**
     * Import translations from file
     *
     * @param string $locale
     * @param string $content
     * @param string $format
     * @return void
     */
    public function import(string $locale, string $content, string $format = 'json'): void
    {
        $translations = match ($format) {
            'json' => json_decode($content, true),
            'php' => include $content,
            default => throw new LocalizationException("Unsupported import format: {$format}")
        };

        foreach ($translations as $key => $value) {
            $this->addTranslation($locale, $key, $value);
        }
    }

    /**
     * Get language direction
     *
     * @param string|null $locale
     * @return string
     */
    public function getDirection(?string $locale = null): string
    {
        $locale = $locale ?? $this->currentLocale;
        return in_array($locale, $this->config['rtl_locales']) ? 'rtl' : 'ltr';
    }

    /**
     * Load translations for locale
     *
     * @param string $locale
     * @return void
     */
    protected function loadTranslations(string $locale): void
    {
        if (!$this->translations->has($locale)) {
            $translations = $this->cache->get($locale) ?? $this->loader->load($locale);
            $this->translations[$locale] = new Collection($translations);
        }
    }

    /**
     * Get translation for key
     *
     * @param string $key
     * @param string $locale
     * @return string|null
     */
    protected function getTranslation(string $key, string $locale): ?string
    {
        return $this->translations->get($locale)?->get($key);
    }

    /**
     * Replace placeholders in translation
     *
     * @param string $translation
     * @param array $replace
     * @return string
     */
    protected function replacePlaceholders(string $translation, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $translation = str_replace(":$key", $value, $translation);
        }
        
        return $translation;
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'fallback_to_default' => true,
            'return_key_if_missing' => true,
            'cache_translations' => true,
            'rtl_locales' => ['ar', 'fa', 'he', 'ur'],
            'auto_detect_locale' => true
        ];
    }
}

class LocaleLoader
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Load translations for locale
     *
     * @param string $locale
     * @return array
     */
    public function load(string $locale): array
    {
        $path = sprintf('%s/%s.php', $this->path, $locale);

        if (!file_exists($path)) {
            throw new LocalizationException("Translation file not found: {$path}");
        }

        return require $path;
    }
}

class TranslationCache
{
    private string $prefix;
    private int $ttl;

    public function __construct(string $prefix = 'translations:', int $ttl = 3600)
    {
        $this->prefix = $prefix;
        $this->ttl = $ttl;
    }

    /**
     * Store translation
     *
     * @param string $locale
     * @param string $key
     * @param string $value
     * @return void
     */
    public function store(string $locale, string $key, string $value): void
    {
        $cacheKey = $this->getCacheKey($locale, $key);
        Cache::put($cacheKey, $value, $this->ttl);
    }

    /**
     * Get translation
     *
     * @param string $locale
     * @param string $key
     * @return string|null
     */
    public function get(string $locale, string $key): ?string
    {
        return Cache::get($this->getCacheKey($locale, $key));
    }

    /**
     * Generate cache key
     *
     * @param string $locale
     * @param string $key
     * @return string
     */
    protected function getCacheKey(string $locale, string $key): string
    {
        return sprintf('%s%s:%s', $this->prefix, $locale, $key);
    }
}

// Middleware for automatic locale detection
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Template\Localization\LocalizationManager;

class LocaleMiddleware
{
    private LocalizationManager $localization;

    public function __construct(LocalizationManager $localization)
    {
        $this->localization = $localization;
    }

    /**
     * Handle request
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->segment(1);

        if ($this->localization->isValidLocale($locale)) {
            $this->localization->setLocale($locale);
        } elseif ($this->localization->config['auto_detect_locale']) {
            $browserLocale = $request->getPreferredLanguage(
                $this->localization->getSupportedLocales()
            );
            $this->localization->setLocale($browserLocale);
        }

        return $next($request);
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Localization\LocalizationManager;
use App\Core\Template\Localization\LocaleLoader;
use App\Core\Template\Localization\TranslationCache;

class LocalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocalizationManager::class, function ($app) {
            return new LocalizationManager(
                new LocaleLoader(resource_path('lang')),
                new TranslationCache(),
                config('localization')
            );
        });
    }

    public function boot(): void
    {
        $blade = $this->app['blade.compiler'];

        // Add Blade directive for translations
        $blade->directive('translate', function ($expression) {
            return "<?php echo app(LocalizationManager::class)->translate($expression); ?>";
        });

        // Add Blade directive for language direction
        $blade->directive('langdir', function ($expression) {
            return "<?php echo app(LocalizationManager::class)->getDirection($expression); ?>";
        });
    }
}
