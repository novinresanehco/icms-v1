<?php

namespace App\Core\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'metadata'
    ];

    protected $casts = [
        'value' => 'json',
        'metadata' => 'array'
    ];
}

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'value',
        'type'
    ];

    protected $casts = [
        'value' => 'json'
    ];
}

namespace App\Core\Settings\Services;

class SettingsManager
{
    private SettingsRepository $repository;
    private SettingsCache $cache;
    private SettingsValidator $validator;

    public function get(string $key, $default = null)
    {
        return $this->cache->remember($key, function() use ($key, $default) {
            return $this->repository->get($key) ?? $default;
        });
    }

    public function set(string $key, $value): void
    {
        $this->validator->validate($key, $value);
        $this->repository->set($key, $value);
        $this->cache->forget($key);
    }

    public function getUserSetting(int $userId, string $key, $default = null)
    {
        return $this->cache->remember("user.$userId.$key", function() use ($userId, $key, $default) {
            return $this->repository->getUserSetting($userId, $key) ?? $default;
        });
    }

    public function setUserSetting(int $userId, string $key, $value): void
    {
        $this->validator->validate($key, $value);
        $this->repository->setUserSetting($userId, $key, $value);
        $this->cache->forget("user.$userId.$key");
    }
}

class SettingsRepository
{
    public function get(string $key)
    {
        return Setting::where('key', $key)->value('value');
    }

    public function set(string $key, $value): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public function getUserSetting(int $userId, string $key)
    {
        return UserSetting::where('user_id', $userId)
            ->where('key', $key)
            ->value('value');
    }

    public function setUserSetting(int $userId, string $key, $value): void
    {
        UserSetting::updateOrCreate(
            [
                'user_id' => $userId,
                'key' => $key
            ],
            ['value' => $value]
        );
    }
}

class SettingsCache
{
    private $cache;
    private int $ttl;

    public function __construct($cache, int $ttl = 3600)
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    public function remember(string $key, \Closure $callback)
    {
        return $this->cache->remember("settings.$key", $this->ttl, $callback);
    }

    public function forget(string $key): void
    {
        $this->cache->forget("settings.$key");
    }

    public function flush(): void
    {
        $this->cache->tags(['settings'])->flush();
    }
}

class SettingsValidator
{
    private array $rules = [];

    public function addRule(string $key, callable $validator): void
    {
        $this->rules[$key] = $validator;
    }

    public function validate(string $key, $value): void
    {
        if (isset($this->rules[$key])) {
            $validator = $this->rules[$key];
            if (!$validator($value)) {
                throw new SettingsValidationException("Invalid value for setting $key");
            }
        }
    }
}

namespace App\Core\Settings\Http\Controllers;

class SettingsController extends Controller
{
    private SettingsManager $settings;

    public function index(): JsonResponse
    {
        $settings = Setting::all()->groupBy('group');
        return response()->json($settings);
    }

    public function show(string $key): JsonResponse
    {
        $value = $this->settings->get($key);
        if ($value === null) {
            return response()->json(['error' => 'Setting not found'], 404);
        }
        return response()->json(['key' => $key, 'value' => $value]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        try {
            $this->settings->set($key, $request->input('value'));
            return response()->json(['message' => 'Setting updated successfully']);
        } catch (SettingsValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

class UserSettingsController extends Controller
{
    private SettingsManager $settings;

    public function show(Request $request, string $key): JsonResponse
    {
        $value = $this->settings->getUserSetting(
            auth()->id(),
            $key
        );
        
        if ($value === null) {
            return response()->json(['error' => 'Setting not found'], 404);
        }
        
        return response()->json(['key' => $key, 'value' => $value]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        try {
            $this->settings->setUserSetting(
                auth()->id(),
                $key,
                $request->input('value')
            );
            return response()->json(['message' => 'Setting updated successfully']);
        } catch (SettingsValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

namespace App\Core\Settings\Console;

class CacheSettingsCommand extends Command
{
    protected $signature = 'settings:cache';

    public function handle(SettingsManager $settings): void
    {
        Setting::chunk(100, function ($settings) {
            foreach ($settings as $setting) {
                Cache::forever("settings.{$setting->key}", $setting->value);
            }
        });
        
        $this->info('Settings cached successfully.');
    }
}
