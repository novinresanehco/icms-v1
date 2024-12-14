<?php

namespace App\Repositories;

use App\Models\Plugin;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class PluginRepository extends BaseRepository
{
    public function __construct(Plugin $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findEnabled(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('enabled', true)
                             ->orderBy('name')
                             ->get();
        });
    }

    public function register(string $name, array $metadata): Plugin
    {
        $plugin = $this->create([
            'name' => $name,
            'version' => $metadata['version'] ?? '1.0.0',
            'description' => $metadata['description'] ?? '',
            'author' => $metadata['author'] ?? '',
            'enabled' => false,
            'metadata' => $metadata
        ]);

        $this->clearCache();
        return $plugin;
    }

    public function enable(int $id): bool
    {
        $result = $this->update($id, ['enabled' => true]);
        $this->clearCache();
        return $result;
    }

    public function disable(int $id): bool
    {
        $result = $this->update($id, ['enabled' => false]);
        $this->clearCache();
        return $result;
    }

    public function updateMetadata(int $id, array $metadata): bool
    {
        $result = $this->update($id, [
            'metadata' => array_merge(
                $this->find($id)->metadata ?? [],
                $metadata
            )
        ]);
        
        $this->clearCache();
        return $result;
    }
}
