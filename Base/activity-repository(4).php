<?php

namespace App\Core\Repositories;

use App\Core\Models\Activity;
use App\Core\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ActivityRepository implements ActivityRepositoryInterface
{
    public function __construct(
        private Activity $model
    ) {}

    public function findById(int $id): ?Activity
    {
        return $this->model
            ->with(['subject', 'causer'])
            ->find($id);
    }

    public function getForSubject(string $subjectType, int $subjectId): Collection
    {
        return $this->model
            ->with(['causer'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->latest()
            ->get();
    }

    public function getByCauser(string $causerType, int $causerId): Collection
    {
        return $this->model
            ->with(['subject'])
            ->where('causer_type', $causerType)
            ->where('causer_id', $causerId)
            ->latest()
            ->get();
    }

    public function getLatest(int $limit = 50): Collection
    {
        return $this->model
            ->with(['subject', 'causer'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function getByType(string $type, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['subject', 'causer'])
            ->where('type', $type)
            ->latest()
            ->paginate($perPage);
    }

    public function store(array $data): Activity
    {
        return $this->model->create($data);
    }

    public function delete(int $id): bool
    {
        return $this->model->findOrFail($id)->delete();
    }

    public function deleteForSubject(string $subjectType, int $subjectId): bool
    {
        return $this->model
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->delete();
    }

    public function deleteByCauser(string $causerType, int $causerId): bool
    {
        return $this->model
            ->where('causer_type', $causerType)
            ->where('causer_id', $causerId)
            ->delete();
    }
}
