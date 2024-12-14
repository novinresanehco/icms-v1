<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Support\Collection;

interface SettingsRepositoryInterface extends RepositoryInterface
{
    public function getAllSettings(): Collection;
    
    public function getValue(string $key, $default = null);
    
    public function setValues(array $settings): bool;
    
    public function getGroup(string $group): Collection;
}
