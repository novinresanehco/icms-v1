<?php

namespace App\Core\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\Models\User;

class Audit extends Model
{
    protected $fillable = [
        'action',
        'entity_type',
        'entity_id',
        'user_id',
        'ip_address',
        'user_agent',
        'data'
    ];

    protected $casts = [
        'data' => 'array'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity()
    {
        return $this->morphTo('entity');
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByEntity($query, string $type, ?int $id = null)
    {
        $query->where('entity_type', $type);

        if ($id !== null) {
            $query->where('entity_id', $id);
        }

        return $query;
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function getChanges(): array
    {
        return $this->data['changes'] ?? [];
    }

    public function getOldValue(string $field)
    {
        return $this->data['changes'][$field]['old'] ?? null;
    }

    public function getNewValue(string $field)
    {
        return $this->data['changes'][$field]['new'] ?? null;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'user_name' => $this->user ? $this->user->name : null,
            'changes' => $this->getChanges()
        ]);
    }
}
