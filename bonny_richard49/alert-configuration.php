<?php

namespace App\Core\Notification\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class AlertConfiguration extends Model
{
    protected $table = 'notification_alert_configurations';

    protected $fillable = [
        'metric_name',
        'severity',
        'threshold',
        'channels',
        'recipients',
        'throttle_minutes',
        'is_active',
        'notification_template',
        'metadata'
    ];

    protected $casts = [
        'channels' => 'array',
        'recipients' => 'array',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'threshold' => 'float'
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForMetric(Builder $query, string $metricName): Builder
    {
        return $query->where('metric_name', $metricName);
    }

    public function scopeForSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function isChannelEnabled(string $channel): bool
    {
        return in_array($channel, $this->channels ?? []);
    }

    public function shouldThrottle(): bool
    {
        return !empty($this->throttle_minutes);
    }

    public function getThrottleDuration(): int
    {
        return $this->throttle_minutes ?? 30; // Default 30 minutes
    }

    public function getRecipientsByChannel(string $channel): array
    {
        return $this->recipients[$channel] ?? [];
    }

    public function getFormattedTemplate(array $data): string
    {
        $template = $this->notification_template ?? $this->getDefaultTemplate();
        
        foreach ($data as $key => $value) {
            $template = str_replace("{{$key}}", $value, $template);
        }
        
        return $template;
    }

    private function getDefaultTemplate(): string
    {
        return "Alert for {metric_name}: Current value {current_value} exceeds threshold {threshold} (Severity: {severity})";
    }
}

namespace App\Core\Notification\Analytics\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_alert_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('metric_name');
            $table->string('severity');
            $table->float('threshold');
            $table->json('channels');
            $table->json('recipients');
            $table->integer('throttle_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notification_template')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['metric_name', 'severity']);
            $table->index(['metric_name', 'severity', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_alert_configurations');
    }
};

namespace App\Core\Notification\Analytics\Repositories;

use App\Core\Notification\Analytics\Models\AlertConfiguration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AlertConfigurationRepository
{
    private const CACHE_KEY = 'notification_alert_configs';
    private const CACHE_TTL = 3600; // 1 hour

    public function getActiveConfigurations(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return AlertConfiguration::active()->get();
        });
    }

    public function getConfigurationForMetric(string $metricName, string $severity): ?AlertConfiguration
    {
        return AlertConfiguration::active()
            ->forMetric($metricName)
            ->forSeverity($severity)
            ->first();
    }

    public function createConfiguration(array $data): AlertConfiguration
    {
        $config = AlertConfiguration::create($data);
        $this->clearCache();
        return $config;
    }

    public function updateConfiguration(AlertConfiguration $config, array $data): AlertConfiguration
    {
        $config->update($data);
        $this->clearCache();
        return $config;
    }

    public function deleteConfiguration(AlertConfiguration $config): bool
    {
        $deleted = $config->delete();
        $this->clearCache();
        return $deleted;
    }

    private function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
