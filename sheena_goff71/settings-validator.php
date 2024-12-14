<?php

namespace App\Core\Settings\Services;

use App\Core\Settings\Models\Setting;
use App\Exceptions\SettingValidationException;

class SettingValidator
{
    public function validateSetting(string $key, $value): void
    {
        $this->validateKey($key);
        $this->validateValue($value);
        $this->validateSystemSetting($key);
    }

    protected function validateKey(string $key): void
    {
        if (empty($key)) {
            throw new SettingValidationException('Setting key cannot be empty');
        }

        if (!preg_match('/^[a-z0-9_\.]+$/', $key)) {
            throw new SettingValidationException('Invalid setting key format');
        }
    }

    protected function validateValue($value): void
    {
        if (is_resource($value)) {
            throw new SettingValidationException('Setting value cannot be a resource');
        }

        if (is_object($value) && !method_exists($value, '__toString')) {
            throw new SettingValidationException('Setting value must be serializable');
        }
    }

    protected function validateSystemSetting(string $key): void
    {
        $setting = Setting::where('key', $key)->first();

        if ($setting && $setting->is_system) {
            throw new SettingValidationException('Cannot modify system settings');
        }
    }
}
