<?php

namespace App\Core\Profile\Repositories;

use App\Core\Profile\Models\Profile;
use App\Core\User\Models\User;
use Illuminate\Support\Collection;

class ProfileRepository
{
    public function create(array $data): Profile
    {
        return Profile::create($data);
    }

    public function update(Profile $profile, array $data): Profile
    {
        $profile->update($data);
        return $profile->fresh();
    }

    public function delete(Profile $profile): bool
    {
        return $profile->delete();
    }

    public function findByUser(User $user): ?Profile
    {
        return Profile::where('user_id', $user->id)->first();
    }

    public function findWithFilters(array $filters = []): Collection
    {
        $query = Profile::query();

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->whereJsonContains('data->first_name', $filters['search'])
                  ->orWhereJsonContains('data->last_name', $filters['search']);
            });
        }

        if (!empty($filters['location'])) {
            $query->whereJsonContains('data->location', $filters['location']);
        }

        if (!empty($filters['complete'])) {
            $query->whereNotNull('data->first_name')
                  ->whereNotNull('data->last_name')
                  ->whereNotNull('data->phone')
                  ->whereNotNull('data->location');
        }

        return $query->get();
    }

    public function getStats(): array
    {
        return [
            'total_profiles' => Profile::count(),
            'complete_profiles' => Profile::whereNotNull('data->first_name')
                                       ->whereNotNull('data->last_name')
                                       ->whereNotNull('data->phone')
                                       ->whereNotNull('data->location')
                                       ->count(),
            'with_avatar' => Profile::whereNotNull('avatar_path')->count(),
            'locations' => Profile::selectRaw("JSON_EXTRACT(data, '$.location') as location")
                                ->whereNotNull('data->location')
                                ->groupBy('location')
                                ->pluck('location', 'count')
                                ->toArray()
        ];
    }
}
