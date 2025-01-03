<?php
namespace App\Core\Settings;

class SettingsManager implements SettingsManagerInterface
{
    private SecurityManager $security;
    private SettingsRepository $settings;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        SettingsRepository $settings,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->settings = $settings;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function get(string $key, SecurityContext $context): mixed
    {
        return $this->security->executeCriticalOperation(
            new GetSettingOperation($key, $this->settings, $this->cache),
            $context
        );
    }

    public function set(string $key, mixed $value, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new SetSettingOperation(
                $key,
                $value,
                $this->settings,
                $this->cache,
                $this->validator,
                $this->audit
            ),
            $context
        );
    }

    public function remove(string $key, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new RemoveSettingOperation(
                $key,
                $this->settings,
                $this->cache,
                $this->audit
            ),
            $context
        );
    }
}

class GetSettingOperation extends CriticalOperation
{
    private string $key;
    private SettingsRepository $settings;
    private CacheManager $cache;

    public function execute(): mixed
    {
        return $this->cache->remember(
            "setting.{$this->key}",
            fn() => $this->settings->get($this->key)
        );
    }

    public function getRequiredPermissions(): array
    {
        return ['settings.read'];
    }
}

class SetSettingOperation extends CriticalOperation
{
    private string $key;
    private mixed $value;
    private SettingsRepository $settings;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Validate setting
        if (!$this->validator->validateSetting($this->key, $this->value)) {
            throw new ValidationException('Invalid setting value');
        }

        // Store setting
        $this->settings->set($this->key, $this->value);

        // Clear cache
        $this->cache->invalidate("setting.{$this->key}");

        // Log change
        $this->audit->logSettingChange($this->key, $this->value);
    }

    public function getRequiredPermissions(): array
    {
        return ['settings.write'];
    }
}

class RemoveSettingOperation extends CriticalOperation
{
    private string $key;
    private SettingsRepository $settings;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Remove setting
        $this->settings->remove($this->key);

        // Clear cache
        $this->cache->invalidate("setting.{$this->key}");

        // Log removal
        $this->audit->logSettingRemoval($this->key);
    }

    public function getRequiredPermissions(): array
    {
        return ['settings.delete'];
    }
}

class SettingsRepository extends BaseRepository
{
    protected function model(): string
    {
        return Setting::class;
    }

    public function get(string $key): mixed
    {
        $setting = $this->model->where('key', $key)->first();
        return $setting ? $this->unserializeValue($setting->value) : null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->model->updateOrCreate(
            ['key' => $key],
            ['value' => $this->serializeValue($value)]
        );
    }

    public function remove(string $key): void
    {
        $this->model->where('key', $key)->delete();
    }

    private function serializeValue(mixed $value): string
    {
        return base64_encode(serialize($value));
    }

    private function unserializeValue(string $value): mixed
    {
        return unserialize(base64_decode($value));
    }
}

// Critical Settings Model
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($setting) {
            static::validateKey($setting->key);
        });

        static::updating(function ($setting) {
            static::validateKey($setting->key);
        });
    }

    private static function validateKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.]+$/', $key)) {
            throw new ValidationException('Invalid setting key format');
        }
    }
}
