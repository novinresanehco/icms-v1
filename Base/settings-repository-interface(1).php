<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface SettingsRepositoryInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): bool;
    public function has(string $key): bool;
    public function delete(string $key): bool;
    public function getGroup(string $group): Collection;
    public function setGroup(string $group, array $settings): bool;
    public function deleteGroup(string $group): bool;
    public function getMultiple(array $keys): array;
    public function setMultiple(array $settings): bool;
    public function getAll(): Collection;
    public function flush(): bool;
}
