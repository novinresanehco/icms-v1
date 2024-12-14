<?php

namespace App\Core\Services\Contracts;

use Illuminate\Support\Collection;

interface SettingServiceInterface
{
    public function getSetting(string $key, $default = null);
    
    public function setSetting(string $key, $value): bool;
    
    public function hasSetting(string $key): bool;
    
    public function removeSetting(string $key): bool;
    
    public function getGroupSettings(string $group): Collection;
    
    public function getAllSettings(): Collection;
    
    public function setManySettings(array $settings): bool;
    
    public function removeManySettings(array $keys): bool;
    
    public function removeGroupSettings(string $group): bool;
}
