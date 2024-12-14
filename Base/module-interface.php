<?php

namespace App\Modules\Contracts;

interface ModuleInterface
{
    public function getName(): string;
    public function getVersion(): string;
    public function getDependencies(): array;
    public function getPermissions(): array;
    public function install(): bool;
    public function uninstall(): bool;
    public function enable(): bool;
    public function disable(): bool;
    public function update(): bool;
    public function getStatus(): string;
    public function getConfig(): array;
}
