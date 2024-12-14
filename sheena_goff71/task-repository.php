<?php

namespace App\Core\Task\Repositories;

use App\Core\Task\Models\Task;
use Illuminate\Support\Collection;

class TaskRepository
{
    public function create(array $data): Task
    {
        return Task::create($data);
    }

    public function update(Task $task, array $data): Task
    {
        $task->update($data);
        return $task->fresh();
    }

    public function delete(Task $task): bool
    {
        return $task->delete();
    }

    public function getWithFilters(array $filters = []): Collection
    {
        $query = Task::query();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['assignee_id'])) {
            $query->where('assignee_id', $filters['assignee_id']);
        }

        if (!empty($filters['due_date_from'])) {
            $query->where('due_date', '>=', $filters['due_date_from']);
        }

        if (!empty($filters['due_date_to'])) {
            $query->where('due_date', '<=', $filters['due_date_to']);
        }

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getTasksByAssignee(int $userId): Collection
    {
        return Task::byAssignee($userId)->get();
    }

    public function getDueTasks(): Collection
    {
        return Task::where('due_date', '<=', now()->addDays(7))
                  ->where('status', '!=', 'completed')
                  ->orderBy('due_date')
                  ->get();
    }

    public function getOverdueTasks(): Collection
    {
        return Task::overdue()->get();
    }

    public function getStats(): array
    {
        return [
            'total_tasks' => Task::count(),
            'pending_tasks' => Task::pending()->count(),
            'completed_tasks' => Task::completed()->count(),
            'overdue_tasks' => Task::overdue()->count(),
            'by_priority' => Task::selectRaw('priority, count(*) as count')
                               ->groupBy('priority')
                               ->pluck('count', 'priority')
                               ->toArray(),
            'by_type' => Task::selectRaw('type, count(*) as count')
                            ->groupBy('type')
                            ->pluck('count', 'type')
                            ->toArray()
        ];
    }
}
