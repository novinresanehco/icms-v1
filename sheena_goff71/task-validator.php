<?php

namespace App\Core\Task\Services;

use App\Core\Task\Models\Task;
use App\Core\Task\Exceptions\TaskValidationException;
use Illuminate\Support\Facades\Validator;

class TaskValidator
{
    public function validateCreate(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|string',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'due_date' => 'nullable|date|after:now',
            'assignee_id' => 'nullable|exists:users,id',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240'
        ]);

        if ($validator->fails()) {
            throw new TaskValidationException(
                'Task validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateUpdate(Task $task, array $data): void
    {
        $validator = Validator::make($data, [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|required|string',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'due_date' => 'nullable|date',
            'assignee_id' => 'nullable|exists:users,id',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240'
        ]);

        if ($validator->fails()) {
            throw new TaskValidationException(
                'Task validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    public function validateStatus(string $status): void
    {
        if (!in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'])) {
            throw new TaskValidationException('Invalid task status');
        }
    }

    public function validatePriority(string $priority): void
    {
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'])) {
            throw new TaskValidationException('Invalid task priority');
        }
    }

    public function validateAssignment(Task $task, int $userId): void
    {
        if ($task->isComplete()) {
            throw new TaskValidationException('Cannot assign completed task');
        }

        if (!User::find($userId)) {
            throw new TaskValidationException('Invalid user ID');
        }
    }

    public function validateAttachment(array $attachment): void
    {
        $validator = Validator::make(['attachment' => $attachment], [
            'attachment' => 'required|file|max:10240'
        ]);

        if ($validator->fails()) {
            throw new TaskValidationException(
                'Invalid attachment',
                $validator->errors()->toArray()
            );
        }
    }
}
