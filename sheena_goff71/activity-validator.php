<?php

namespace App\Core\Activity\Services;

use App\Exceptions\ActivityValidationException;
use Illuminate\Support\Facades\Validator;

class ActivityValidator
{
    public function validateLog(string $type, array $data): void
    {
        $validator = Validator::make(
            [
                'type' => $type,
                'data' => $data
            ],
            [
                'type' => 'required|string|max:255',
                'data' => 'required|array'
            ]
        );

        if ($validator->fails()) {
            throw new ActivityValidationException(
                'Activity validation failed',
                $validator->errors()->toArray()
            );
        }

        $this->validateActivityType($type);
    }

    protected function validateActivityType(string $type): void
    {
        $allowedTypes = config('activity.allowed_types', []);

        if (!empty($allowedTypes) && !in_array($type, $allowedTypes)) {
            throw new ActivityValidationException(
                "Invalid activity type: {$type}"
            );
        }
    }

    public function validateFilters(array $filters): void
    {
        $validator = Validator::make($filters, [
            'type' => 'sometimes|string|max:255',
            'user_id' => 'sometimes|integer|exists:users,id',
            'subject' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'ip_address' => 'sometimes|ip'
        ]);

        if ($validator->fails()) {
            throw new ActivityValidationException(
                'Invalid filter parameters',
                $validator->errors()->toArray()
            );
        }
    }
}
