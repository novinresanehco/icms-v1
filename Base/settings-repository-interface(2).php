<?php

namespace App\Repositories\Contracts;

interface SettingsRepositoryInterface
{
    public function get(string $key, $default = null);
    public function set(string $key, $value);
    public function setMany(array $settings);
    public function forget(string $key);
    public function has(string $key): bool;
    public function all(): array;
    public function getByGroup(string $group): array;
    public function flush();
    public function flushGroup(string $group);
    public function getGroups(): array;
}
