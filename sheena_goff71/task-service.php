<?php

namespace App\Core\Task\Services;

use App\Core\Task\Models\Task;
use App\Core\Task\Repositories\TaskRepository;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
        private TaskRepository $repository,
        private TaskValidator $validator,
        private TaskProcessor $processor
    ) {}

    public function create(array $data): Task
    {
        $this->validator->validateCreate($data);

        return DB::transaction(function () use ($data) {
            $task = $this->repository->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'priority' => $data['priority'] ?? 'normal',
                'due_date' => $data['due_date'] ?? null,
                'assignee_id' => $data['assignee_id'] ?? null,
                'status' => 'pending'
            ]);

            if (!empty($data['attachments'])) {
                $this->processor->processAttachments($task, $data['attachments']);
            }

            return $task;
        });
    }

    public function update(Task $task, array $data): Task
    {
        $this->validator->validateUpdate($task, $data);

        return DB::transaction(function () use ($task, $data) {
            $task = $this->repository->update($task, $data);

            if (isset($data['attachments'])) {
                $this->processor->processAttachments($task, $data['attachments']);
            }

            return $task;
        });
    }

    public function delete(Task $task): bool
    {
        return DB::transaction(function () use ($task) {
            $this->processor->deleteAttachments($task);
            return $this->repository->delete($task);
        });
    }

    public function assign(Task $task, int $userId): Task
    {
        $this->validator->validateAssignment($task, $userId);
        return $this->repository->update($task, ['assignee_id' => $userId]);
    }

    public function updateStatus(Task $task, string $status): Task
    {
        $this->validator->validateStatus($status);
        return $this->repository->update($task, ['status' => $status]);
    }

    public function updatePriority(Task $task, string $priority): Task
    {
        $this->validator->validatePriority($priority);
        return $this->repository->update($task, ['priority' => $priority]);
    }

    public function addAttachment(Task $task, array $attachment): void
    {
        $this->validator->validateAttachment($attachment);
        $this->processor->processAttachments($task, [$attachment]);
    }

    public function removeAttachment(Task $task, int $attachmentId): void
    {
        $this->processor->deleteAttachment($task, $attachmentId);
    }

    public function getAssignedTasks(int $userId): Collection
    {
        return $this->repository->getTasksByAssignee($userId);
    }

    public function getDueTasks(): Collection
    {
        return $this->repository->getDueTasks();
    }

    public function searchTasks(array $filters = []): Collection
    {
        return $this->repository->getWithFilters($filters);
    }

    public function getStats(): array
    {
        return $this->repository->getStats();
    }
}
