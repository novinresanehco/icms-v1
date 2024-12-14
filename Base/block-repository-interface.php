<?php

namespace App\Repositories\Contracts;

use App\Models\Block;
use Illuminate\Support\Collection;

interface BlockRepositoryInterface
{
    public function findByIdentifier(string $identifier): ?Block;
    public function getActiveBlocks(): Collection;
    public function createWithContent(array $data, array $content): Block;
    public function updateWithContent(int $id, array $data, array $content): bool;
    public function getBlocksByRegion(string $region): Collection;
    public function getBlocksByType(string $type): Collection;
    public function activateBlock(int $id): bool;
    public function deactivateBlock(int $id): bool;
    public function reorderBlocks(string $region, array $order): bool;
    public function duplicateBlock(int $id, string $newIdentifier): Block;
    public function getBlockVersions(int $id): Collection;
}
