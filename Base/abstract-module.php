<?php

namespace App\Modules;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Exceptions\ModuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class AbstractModule implements ModuleInterface
{
    protected string $name;
    protected string $version;
    protected array $dependencies = [];
    protected array $permissions = [];
    protected string $status = 'disabled';
    protected array $config = [];

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function install(): bool
    {
        try {
            DB::beginTransaction();

            $this->registerPermissions();
            $this->runMigrations();
            $this->publishAssets();
            $this->publishConfig();

            $this->status = 'installed';
            DB::commit();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ModuleException("Module installation failed: {$e->getMessage()}");
        }
    }

    public function uninstall(): bool
    {
        try {
            DB::beginTransaction();

            $this->removePermissions();
            $this->rollbackMigrations();
            $this->removeAssets();
            $this->removeConfig();

            $this->status = 'uninstalled';
            DB::commit();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ModuleException("Module uninstallation failed: {$e->getMessage()}");
        }
    }

    public function enable(): bool
    {
        if ($this->status !== 'installed') {
            throw new ModuleException('Module must be installed before enabling');
        }

        $this->status = 'enabled';
        return true;
    }

    public function disable(): bool
    {
        if ($this->status === 'uninstalled') {
            throw new ModuleException('Module is not installed');
        }

        $this->status = 'disabled';
        return true;
    }

    abstract protected function runMigrations(): void;
    abstract protected function rollbackMigrations(): void;
    abstract protected function publishAssets(): void;
    abstract protected function removeAssets(): void;
    abstract protected function publishConfig(): void;
    abstract protected function removeConfig(): void;

    protected function registerPermissions(): void
    {
        foreach ($this->permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission['name'],
                'guard_name' => $permission['guard'] ?? 'web',
                'module' => $this->name,
                'description' => $permission['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function removePermissions(): void
    {
        DB::table('permissions')->where('module', $this->name)->delete();
    }
}
