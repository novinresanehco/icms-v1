<?php

namespace App\Core\Notification\Analytics\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class AnalyticsStorage
{
    private array $config;

    public function __construct()
    {
        $this->config = config('analytics.storage');
    }

    public function storeAnalytics(string $type, array $data): string
    {
        $id = $this->generateAnalyticsId();
        
        DB::table('analytics_data')->insert([
            'id' => $id,
            'type' => $type,
            'data' => json_encode($data),
            'created_at' => now(),
            'expires_at' => now()->addDays($this->config['retention_days']),
            'status' => 'active'
        ]);

        $this->updateAnalyticsIndex($type, $id);
        return $id;
    }

    public function getAnalytics(string $id): ?array
    {
        $data = DB::table('analytics_data')
            ->where('id', $id)
            ->where('status', 'active')
            ->first();

        if (!$data) {
            return null;
        }

        return [
            'id' => $data->id,
            'type' => $data->type,
            'data' => json_decode($data->data, true),
            'created_at' => $data->created_at,
            'expires_at' => $data->expires_at
        ];
    }

    public function getAnalyticsByType(string $type, array $filters = []): array
    {
        $query = DB::table('analytics_data')
            ->where('type', $type)
            ->where('status', 'active');

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        return $query->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'data' => json_decode($item->data, true),
                    'created_at' => $item->created_at,
                    'expires_at' => $item->expires_at
                ];
            })
            ->all();
    }

    public function deleteAnalytics(string $id): bool
    {
        return DB::table('analytics_data')
            ->where('id', $id)
            ->update(['status' => 'deleted']);
    }

    public function cleanupExpiredAnalytics(): int
    {
        return DB::table('analytics_data')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    private function generateAnalyticsId(): string
    {
        return uniqid('analytics_', true);
    }

    private function updateAnalyticsIndex(string $type, string $id): void
    {
        $key = "analytics_index:{$type}";
        $score = now()->timestamp;

        Cache::tags(['analytics', $type])->put(
            $key . ':' . $id,
            $score,
            now()->addDays($this->config['index_retention_days'])
        );
    }
}
