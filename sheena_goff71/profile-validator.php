<?php

namespace App\Core\Profile\Services;

use App\Core\Profile\Models\Profile;
use App\Exceptions\ProfileValidationException;
use Illuminate\Support\Facades\Validator;

class ProfileValidator
{
    public function validateCreate(array $data): void
    {
        $validator = Validator::make($data, [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'location' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'birthdate' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female,other',
            'avatar' => 'nullable|image|max:2048',
            'preferences' => 'nullable|array',
            'privacy' => 'nullable|array',
            'social_links' => 'nullable|array',
            'social_links.*' => 'url'
        ]);

        if ($validator->fails()) {
            throw new ProfileValidationException(
                'Profile validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateUpdate(Profile $profile, array $data): void
    {
        $validator = Validator::make($data, [
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'location' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'birthdate' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female,other',
            'avatar' => 'nullable|image|max:2048',
            'preferences' => 'nullable|array',
            'privacy' => 'nullable|array',
            'social_links' => 'nullable|array',
            'social_links.*' => 'url'
        ]);

        if ($validator->fails()) {
            throw new ProfileValidationException(
                'Profile validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validatePreferences(array $preferences): void
    {
        $validator = Validator::make(['preferences' => $preferences], [
            'preferences' => 'required|array',
            'preferences.notifications' => 'nullable|array',
            'preferences.theme' => 'nullable|string|in:light,dark,system',
            'preferences.language' => 'nullable|string|size:2',
            'preferences.timezone' => 'nullable|string|timezone'
        ]);

        if ($validator->fails()) {
            throw new ProfileValidationException(
                'Preferences validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validatePrivacySettings(array $settings): void
    {
        $validator = Validator::make(['settings' => $settings], [
            'settings' => 'required|array',
            'settings.*' => 'required|string|in:public,private'
        ]);

        if ($validator->fails()) {
            throw new ProfileValidationException(
                'Privacy settings validation failed',
                $validator->errors()->toArray()
            );
        }
    }
}
