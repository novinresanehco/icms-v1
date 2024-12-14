<?php

namespace App\Core\Notification\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class NotificationDeliveryMetrics extends Model
{
    protected $table = 'notification_delivery_metrics';

    protected $fillable = [
        'notification_id',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'converted_at',
        'status',
        'metadata'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'converted_at' => 'datetime',
        'metadata' => 'array'
    ];

    public function getDeliveryTime(): ?float
    {
        if (!$this->delivered_at || !$this->sent_at) {
            return null;
        }

        return $this->delivered_at->diffInSeconds($this->sent_at);
    }

    public function getTimeToOpen(): ?float
    {
        if (!$this->opened_at || !$this->delivered_at) {
            return null;
        }

        return $this->opened_at->diffInSeconds($this->delivered_at);
    }

    public function getTimeToClick(): ?float
    {
        if (!$this->clicked_at || !$this->opened_at) {
            return null;
        }

        return $this->clicked_at->diffInSeconds($this->opened_at);
    }

    public function getTimeToConvert(): ?float
    {
        if (!$this->converted_at || !$this->clicked_at) {
            return null;
        }

        return $this->converted_at->diffInSeconds($this->clicked_at);
    }

    public function getTotalJourneyTime(): ?float
    {
        if (!$this->converted_at || !$this->sent_at) {
            return null;
        }

        return $this->converted_at->diffInSeconds($this->sent_at);
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered' && !is_null($this->delivered_at);
    }

    public function isOpened(): bool
    {
        return !is_null($this->opened_at);
    }

    public function isClicked(): bool
    {
        return !is_null($this->clicked_at);
    }

    public function isConverted(): bool
    {
        return !is_null($this->converted_at);
    }

    public function markDelivered(): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => Carbon::now()
        ]);
    }

    public function markOpened(): void
    {
        if (!$this->opened_at) {
            $this->update(['opened_at' => Carbon::now()]);
        }
    }

    public function markClicked(): void
    {
        if (!$this->clicked_at) {
            $this->update(['clicked_at' => Carbon::now()]);
        }
    }

    public function markConverted(): void
    {
        if (!$this->converted_at) {
            $this->update(['converted_at' => Carbon::now()]);
        }
    }

    public function addMetadata(array $metadata): void
    {
        $this->metadata = array_merge($this->metadata ?? [], $metadata);
        $this->save();
    }
}
