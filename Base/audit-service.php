<?php

namespace App\Core\Services\Audit;

use Illuminate\Database\Eloquent\Model;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function log(string $action, Model $model, array $oldAttributes = [], array $newAttributes = []): void
    {
        if (!$this->shouldAudit($action, $model)) {
            return;
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'old_values' => $this->filterAttributes($oldAttributes),
            'new_values' => $this->filterAttributes($newAttributes),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $this->collectMetadata($model)
        ]);
    }

    public function getHistory(Model $model): array
    {
        return AuditLog::where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($log) {
                return [
                    'action' => $log->action,
                    'user' => $log->user->name,
                    'timestamp' => $log->created_at->toDateTimeString(),
                    'changes' => $this->formatChanges($log->old_values, $log->new_values),
                    'metadata' => $log->metadata
                ];
            })
            ->toArray();
    }

    protected function shouldAudit(string $action, Model $model): bool
    {
        if (!config('cms.auditing.enabled', true)) {
            return false;
        }

        $auditableActions = config('cms.auditing.actions', ['created', 'updated', 'deleted']);
        return in_array($action, $auditableActions);
    }

    protected function filterAttributes(array $attributes): array
    {
        $excluded = config('cms.auditing.excluded_attributes', ['password', 'remember_token']);
        return array_diff_key($attributes, array_flip($excluded));
    }

    protected function collectMetadata(Model $model): array
    {
        return [
            'timestamp' => now()->toDateTimeString(),
            'session_id' => session()->getId(),
            'route' => request()->route() ? request()->route()->getName() : null,
            'custom' => $this->getCustomMetadata($model)
        ];
    }

    protected function getCustomMetadata(Model $model): array
    {
        if (method_exists($model, 'getAuditMetadata')) {
            return $model->getAuditMetadata();
        }
        return [];
    }

    protected function formatChanges(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $old) || $old[$key] !== $value) {
                $changes[$key] = [
                    'old' => $old[$key] ?? null,
                    'new' => $value
                ];
            }
        }
        return $changes;
    }
}
