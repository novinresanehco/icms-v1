<?php

namespace App\Core\Localization\Models;

class Language extends Model
{
    protected $fillable = [
        'name',
        'code',
        'locale',
        'direction',
        'status',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'status' => 'boolean'
    ];
}

class Translation extends Model
{
    protected $fillable = [
        'language_id',
        'group',
        'key',
        'value',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];
}

namespace App\Core\Localization\Services;

class LocalizationManager
{
    private TranslationLoader $loader;
    private TranslationCache $cache;
    private LocaleDetector $detector;
    private TranslationRepository $repository;

    public function setLocale(string $locale): void
    {
        if (!$this->isValidLocale($locale)) {
            throw new InvalidLocaleException("Invalid locale: {$locale}");
        }
        
        app()->setLocale($locale);
        session(['locale' => $locale]);
    }

    public function translate(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        
        return $this->cache->remember("translation.{$locale}.{$key}", function () use ($key, $locale) {
            return $this->loader->load($key, $locale);
        });
    }

    public function import(string $locale, array $translations): void
    {
        foreach ($translations as $group => $items) {
            foreach ($items as $key => $value) {
                $this->repository->set($locale, $group, $key, $value);
            }
        }
        
        $this->cache->flush();
    }

    public function export(string $locale): array
    {
        return $this->repository->getByLocale($locale);
    }
}

class TranslationLoader
{
    private TranslationRepository $repository;

    public function load(string $key, string $locale): ?string
    {
        [$group, $item] = explode('.', $key, 2);
        
        return $this->repository->get($locale, $group, $item);
    }

    public function loadGroup(string $group, string $locale): array
    {
        return $this->repository->getGroup($locale, $group);
    }
}

class LocaleDetector
{
    private array $supported;

    public function detect(Request $request): string
    {
        $locale = $this->fromSession()
            ?? $this->fromHeader($request)
            ?? $this->fromSubdomain($request)
            ?? $this->getDefault();

        return $this->ensureSupported($locale);
    }

    private function fromSession(): ?string
    {
        return session('locale');
    }

    private function fromHeader(Request $request): ?string
    {
        return $request->getPreferredLanguage($this->supported);
    }

    private function fromSubdomain(Request $request): ?string
    {
        $host = $request->getHost();
        $parts = explode('.', $host);
        
        return in_array($parts[0], $this->supported) ? $parts[0] : null;
    }
}

class TranslationCache
{
    private Cache $cache;
    private int $ttl;

    public function remember(string $key, Closure $callback)
    {
        return $this->cache->remember($key, $this->ttl, $callback);
    }

    public function flush(): void
    {
        $this->cache->tags(['translations'])->flush();
    }
}

namespace App\Core\Localization\Http\Controllers;

class LocalizationController extends Controller
{
    private LocalizationManager $localization;

    public function setLocale(Request $request): JsonResponse
    {
        try {
            $request->validate(['locale' => 'required|string']);
            $this->localization->setLocale($request->input('locale'));
            return response()->json(['message' => 'Locale set successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function import(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'locale' => 'required|string',
                'translations' => 'required|array'
            ]);
            
            $this->localization->import(
                $request->input('locale'),
                $request->input('translations')
            );
            
            return response()->json(['message' => 'Translations imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function export(string $locale): JsonResponse
    {
        try {
            $translations = $this->localization->export($locale);
            return response()->json($translations);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

namespace App\Core\Localization\Middleware;

class LocalizationMiddleware
{
    private LocalizationManager $localization;
    private LocaleDetector $detector;

    public function handle(Request $request, Closure $next)
    {
        $locale = $this->detector->detect($request);
        $this->localization->setLocale($locale);
        
        return $next($request);
    }
}

namespace App\Core\Localization\Console;

class ImportTranslationsCommand extends Command
{
    protected $signature = 'translations:import {locale} {file}';

    public function handle(LocalizationManager $localization): void
    {
        $locale = $this->argument('locale');
        $file = $this->argument('file');
        
        $translations = json_decode(file_get_contents($file), true);
        $localization->import($locale, $translations);
        
        $this->info('Translations imported successfully.');
    }
}
