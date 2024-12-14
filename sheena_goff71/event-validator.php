<?php

namespace App\Core\Event\Services;

use App\Core\Event\Exceptions\EventValidationException;

class EventValidator
{
    public function validateCreate(array $data): void
    {
        if (empty($data['name'])) {
            throw new EventValidationException('Event name is required');
        }

        if (empty($data['type'])) {
            throw new EventValidationException('Event type is required');
        }

        if (!is_array($data['data'])) {
            throw new EventValidationException('Event data must be an array');
        }

        $this->validateType($data['type']);
        $this->validateScheduledAt($data['scheduled_at'] ?? null);
    }

    protected function validateType(string $type): void
    {
        $allowedTypes = config('events.types', []);
        
        if (!empty($allowedTypes) && !in_array($type, $allowedTypes)) {
            throw new EventValidationException("Invalid event type: {$type}");
        }
    }

    protected function validateScheduledAt(?string $scheduledAt): void
    {
        if ($scheduledAt !== null) {
            try {
                $date = new \DateTime($scheduledAt);
                if ($date < new \DateTime()) {
                    throw new EventValidationException('Scheduled time cannot be in the past');
                }
            } catch (\Exception $e) {
                throw new EventValidationException('Invalid scheduled time format');
            }
        }
    }
}
