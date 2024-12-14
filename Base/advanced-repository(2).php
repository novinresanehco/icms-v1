<?php

namespace App\Repositories;

use App\Models\Translation;
use App\Core\Repositories\AbstractRepository;
use Illuminate\Support\Collection;

class TranslationRepository extends AbstractRepository
{
    protected array $with = ['language'];

    public function findTranslation(string $model, int $modelId, string $locale): ?Translation
    {
        return $this->executeQuery(function() use ($model, $modelId, $locale) {
            return $this->model->where([
                'translatable_type' => $model,
                'translatable_id' => $modelId,
                'locale' => $locale
            ])->first();
        });
    }

    public function setTranslation(string $model, int $modelId, string $locale, array $data): Translation
    {
        return $this->model->updateOrCreate(
            [
                'translatable_type' => $model,
                'translatable_id' => $modelId,
                'locale' => $locale
            ],
            [
                'data' => $data,
                'status' => 'active'
            ]
        );
    }

    public function getAvailableTranslations(string $model, int $modelId): Collection
    {
        return $this->executeQuery(function() use ($model, $modelId) {
            return $this->model->where([
                'translatable_type' => $model,
                'translatable_id' => $modelId
            ])->get();
        });
    }
}

class CacheRepository extends AbstractRepository
{
    public function remember(string $key, $data, int $ttl = 3600): void
    {
        $this->create([
            'key' => $key,
            'value' => serialize($data),
            'expires_at' => now()->addSeconds($ttl)
        ]);
    }

    public function forget(string $key): void
    {
        $this->model->where('key', $key)->delete();
    }

    public function get(string $key)
    {
        $cache = $this->model->where('key', $key)
            ->where('expires_at', '>', now())
            ->first();

        return $cache ? unserialize($cache->value) : null;
    }

    public function cleanup(): int
    {
        return $this->model->where('expires_at', '<=', now())->delete();
    }
}

class MetadataRepository extends AbstractRepository 
{
    public function setMetadata(string $model, int $modelId, array $metadata): void
    {
        $this->model->updateOrCreate(
            [
                'metadatable_type' => $model,
                'metadatable_id' => $modelId
            ],
            ['data' => $metadata]
        );
    }

    public function getMetadata(string $model, int $modelId): array
    {
        return $this->executeQuery(function() use ($model, $modelId) {
            $metadata = $this->model->where([
                'metadatable_type' => $model,
                'metadatable_id' => $modelId
            ])->first();

            return $metadata ? $metadata->data : [];
        });
    }
}

class LogRepository extends AbstractRepository
{
    public function log(string $channel, string $level, string $message, array $context = []): void
    {
        $this->create([
            'channel' => $channel,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'created_at' => now()
        ]);
    }

    public function getByChannel(string $channel, int $limit = 100): Collection
    {
        return $this->model->where('channel', $channel)
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function getByLevel(string $level, int $limit = 100): Collection
    {
        return $this->model->where('level', $level)
            ->latest()
            ->limit($limit)
            ->get();
    }
}
