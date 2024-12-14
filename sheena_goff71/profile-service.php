<?php

namespace App\Core\Profile\Services;

use App\Core\Profile\Models\Profile;
use App\Core\Profile\Repositories\ProfileRepository;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\{DB, Storage};

class ProfileService
{
    public function __construct(
        private ProfileRepository $repository,
        private ProfileValidator $validator,
        private ProfileMediaHandler $mediaHandler
    ) {}

    public function createProfile(User $user, array $data): Profile
    {
        $this->validator->validateCreate($data);

        return DB::transaction(function () use ($user, $data) {
            $profile = $this->repository->create([
                'user_id' => $user->id,
                'data' => $this->prepareProfileData($data)
            ]);

            if (!empty($data['avatar'])) {
                $this->mediaHandler->handleAvatar($profile, $data['avatar']);
            }

            return $profile;
        });
    }

    public function updateProfile(Profile $profile, array $data): Profile
    {
        $this->validator->validateUpdate($profile, $data);

        return DB::transaction(function () use ($profile, $data) {
            if (!empty($data['avatar'])) {
                $this->mediaHandler->handleAvatar($profile, $data['avatar']);
            }

            return $this->repository->update(
                $profile,
                ['data' => $this->prepareProfileData($data)]
            );
        });
    }

    public function updatePreferences(Profile $profile, array $preferences): Profile
    {
        $this->validator->validatePreferences($preferences);
        
        $data = $profile->data;
        $data['preferences'] = array_merge(
            $data['preferences'] ?? [],
            $preferences
        );

        return $this->repository->update($profile, ['data' => $data]);
    }

    public function updatePrivacySettings(Profile $profile, array $settings): Profile
    {
        $this->validator->validatePrivacySettings($settings);
        
        $data = $profile->data;
        $data['privacy'] = array_merge(
            $data['privacy'] ?? [],
            $settings
        );

        return $this->repository->update($profile, ['data' => $data]);
    }

    public function getProfile(User $user): ?Profile
    {
        return $this->repository->findByUser($user);
    }

    public function deleteProfile(Profile $profile): bool
    {
        return DB::transaction(function () use ($profile) {
            $this->mediaHandler->deleteAvatar($profile);
            return $this->repository->delete($profile);
        });
    }

    protected function prepareProfileData(array $data): array
    {
        $allowedFields = [
            'first_name',
            'last_name',
            'bio',
            'location',
            'phone',
            'birthdate',
            'gender',
            'preferences',
            'privacy',
            'social_links',
            'metadata'
        ];

        return array_filter(
            array_intersect_key($data, array_flip($allowedFields))
        );
    }
}
