<?php

namespace App\Core\Notification\Http\Resources;

use Illuminate\Http\Resources\Json\{JsonResource, ResourceCollection};

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'status' => $this->status,
            'channels' => $this->channels,
            'metadata' => [
                'is_read' => $this->read_at !== null,
                'is_actionable' => isset($this->data['action_url']),
                'priority' => $this->data['priority'] ?? 'normal',
            ]
        ];
    }
}

class NotificationCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'unread_count' => $this->collection->whereNull('read_at')->count(),
                'total_count' => $this->collection->count()
            ]
        ];
    }
}

class NotificationPreferenceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'enabled' => (bool) $this->enabled,
            'settings' => $this->settings,
            'updated_at' => $this->updated_at->toIso8601String()
        ];
    }
}

class NotificationPreferenceCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'enabled_channels' => $this->collection->where('enabled', true)->pluck('channel'),
                'total_channels' => $this->collection->count()
            ]
        ];
    }
}

class NotificationTemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'channels' => $this->channels,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'active' => (bool) $this->active,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'usage_count' => $this->usage_count ?? 0
        ];
    }
}

class NotificationTemplateCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'active_count' => $this->collection->where('active', true)->count(),
                'total_count' => $this->collection->count(),
                'types' => $this->collection->pluck('type')->unique()->values()
            ]
        ];
    }
}

class NotificationChannelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'name' => $this->name,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'settings_schema' => $this->settings_schema,
            'supports_preferences' => $this->supports_preferences,
            'requires_configuration' => $this->requires_configuration,
            'is_configured' => $this->isConfigured(),
            'is_available' => $this->isAvailable()
        ];
    }
}