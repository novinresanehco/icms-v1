<?php

namespace App\Core\Repositories;

use App\Core\Models\Activity;
use App\Core\Exceptions\ActivityException;
use Illuminate\Database\Eloquent\{Model, Collection, Builder};
use Illuminate\Support\Facades\DB;

class ActivityRepository extends Repository
{
    protected array $with = ['causer', 'subject'];

    public function log(
        string $description,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = []
    ): Model {
        return $this->create([
            'description' => $description,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'causer_type' => $causer ? get_class($causer) : null,
            'causer_id' => $causer?->getKey(),
            'properties' => $properties,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function forSubject(Model $subject): Collection
    {
        return $this->query()
            ->where('subject_type', get_class($subject))
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    public function getByCauser(Model $causer): Collection
    {
        return $this->query()
            ->where('causer_type', get_class($causer))
            ->where('causer_id', $causer->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    public function getLatest(int $limit = 50): Collection
    {
        return $this->query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function clean(int $daysOld = 30): int
    {
        return $this->query()
            ->where('created_at', '<', now()->subDays($daysOld))
            ->delete();
    }
}

class ActivityTypeRepository extends Repository
{
    public function registerType(string $type, array $config = []): Model
    {
        return $this->create([
            'name' => $type,
            'display_name' => $config['display_name'] ?? $type,
            'description' => $config['description'] ?? '',
            'color' => $config['color'] ?? 'gray',
            'icon' => $config['icon'] ?? 'circle',
            'config' => $config
        ]);
    }

    public function getType(string $type): ?Model
    {
        return $this->query()
            ->where('name', $type)
            ->first();
    }

    public function getActiveTypes(): Collection
    {
        return $this->query()
            ->where('active', true)
            ->orderBy('display_name')
            ->get();
    }
}

class AuditTrailRepository extends Repository
{
    public function recordChanges(
        Model $model,
        array $oldValues,
        array $newValues,
        ?Model $causer = null
    ): Model {
        return $this->create([
            'auditable_type' => get_class($model),
            'auditable_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'event' => 'updated',
            'causer_type' => $causer ? get_class($causer) : null,
            'causer_id' => $causer?->getKey(),
            'ip_address' => request()->ip()
        ]);
    }

    public function getChanges(Model $model): Collection
    {
        return $this->query()
            ->where('auditable_type', get_class($model))
            ->where('auditable_id', $model->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    public function getChangesByUser(Model $user): Collection
    {
        return $this->query()
            ->where('causer_type', get_class($user))
            ->where('causer_id', $user->getKey())
            ->orderByDesc('created_at')
            ->get();
    }
}

class ActivityBatchRepository extends Repository
{
    public function startBatch(string $name, array $metadata = []): Model
    {
        return $this->create([
            'name' => $name,
            'metadata' => $metadata,
            'status' => 'running',
            'started_at' => now()
        ]);
    }

    public function completeBatch(Model $batch): bool
    {
        return $this->update($batch, [
            'status' => 'completed',
            'completed_at' => now()
        ]);
    }

    public function failBatch(Model $batch, string $error): bool
    {
        return $this->update($batch, [
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now()
        ]);
    }

    public function getRunningBatches(): Collection
    {
        return $this->query()
            ->where('status', 'running')
            ->orderByDesc('started_at')
            ->get();
    }
}
