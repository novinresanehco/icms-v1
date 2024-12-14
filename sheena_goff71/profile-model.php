<?php

namespace App\Core\Profile\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\Models\User;

class Profile extends Model
{
    protected $fillable = [
        'user_id',
        'data',
        'avatar_path',
        'last_updated_at'
    ];

    protected $casts = [
        'data' => 'array',
        'last_updated_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getName(): string
    {
        return trim(
            ($this->data['first_name'] ?? '') . ' ' . 
            ($this->data['last_name'] ?? '')
        );
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatar_path ? 
            Storage::url($this->avatar_path) : 
            null;
    }

    public function getPreferences(): array
    {
        return $this->data['preferences'] ?? [];
    }

    public function getPrivacySettings(): array
    {
        return $this->data['privacy'] ?? [];
    }

    public function getSocialLinks(): array
    {
        return $this->data['social_links'] ?? [];
    }

    public function hasCompletedProfile(): bool
    {
        $requiredFields = ['first_name', 'last_name', 'phone', 'location'];
        
        foreach ($requiredFields as $field) {
            if (empty($this->data[$field])) {
                return false;
            }
        }

        return true;
    }

    public function isFieldVisible(string $field, ?User $viewer = null): bool
    {
        $privacy = $this->getPrivacySettings();
        $visibility = $privacy[$field] ?? 'public';

        return match($visibility) {
            'public' => true,
            'private' => $viewer && $viewer->id === $this->user_id,
            default => false
        };
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'name' => $this->getName(),
            'avatar_url' => $this->getAvatarUrl(),
            'has_completed_profile' => $this->hasCompletedProfile()
        ]);
    }
}
