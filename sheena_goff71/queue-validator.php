<?php

namespace App\Core\Queue\Services;

use App\Exceptions\QueueValidationException;
use Illuminate\Support\Facades\Validator;

class QueueValidator
{
    public function validateJob(string $type, array $data): void
    {
        $this->validateType($type);
        $this->validateData($data);
    }

    protected function validateType(string $type): void
    {
        if (empty($type)) {
            throw new QueueValidationException('Job type cannot be empty');
        }

        $allowedTypes = config('queue.allowed_job_types', []);
        
        if (!empty($allowedTypes) && !in_array($type, $allowedTypes)) {
            throw new QueueValidationException("Invalid job type: {$type}");
        }
    }

    protected function validateData(array $data): void
    {
        $validator = Validator::make(['data' => $data], [
            'data' => 'required|array'
        ]);

        if ($validator->fails()) {
            throw new QueueValidationException(
                'Invalid job data',
                $validator->errors()->toArray()
            );
        }
    }
}
