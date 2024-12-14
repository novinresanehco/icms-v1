<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Setting;
use Illuminate\Support\Collection;

interface SettingRepositoryInterface
{
    public function get(string $key, $default = null);
    
    public function set(string $key, $value): bool;
    
    public function has(string $key): bool;
    
    public function remove(string $key): bool;
    
    public function getAllByGroup(string $group): Collection;
    
    public function getAll(): Collection;
    
    public function setMany(array $settings): bool;
    
    public function removeMany(array $keys): bool;
    
    public function removeByGroup(string $group): bool;
}
