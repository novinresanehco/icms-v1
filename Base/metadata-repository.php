<?php

namespace App\Core\Repositories;

use App\Models\Metadata;

class MetadataRepository extends AdvancedRepository 
{
    protected $model = Metadata::class;
    
    public function setMetadata(string $model, int $modelId, array $metadata): void
    {
        $this->executeTransaction(function() use ($model, $modelId, $metadata) {
            $this->model->updateOrCreate(
                [
                    'metadatable_type' => $model,
                    'metadatable_id' => $modelId
                ],
                [
                    'data' => $metadata,
                    'updated_at' => now()
                ]
            );
        });
    }

    public function getMetadata(string $model, int $modelId): array
    {
        return $this->executeWithCache(__METHOD__, function() use ($model, $modelId) {
            $metadata = $this->model->where([
                'metadatable_type' => $model,
                'metadatable_id' => $modelId
            ])->first();

            return $metadata ? $metadata->data : [];
        }, $model, $modelId);
    }

    public function deleteMetadata(string $model, int $modelId): void
    {
        $this->executeTransaction(function() use ($model, $modelId) {
            $this->model->where([
                'metadatable_type' => $model,
                'metadatable_id' => $modelId
            ])->delete();
        });
    }

    public function findByValue(string $key, $value): array
    {
        return $this->executeQuery(function() use ($key, $value) {
            return $this->model
                ->whereJsonContains("data->{$key}", $value)
                ->get()
                ->toArray();
        });
    }
}
