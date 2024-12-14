<?php

namespace App\Core\Repositories\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    protected function logActivity(Model $model, string $action, array $oldData = [], array $newData = []): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'model_type' => get_class($model),
            'model_id' => $model->id,
            'action' => $action,
            'old_data' => !empty($oldData) ? json_encode($oldData) : null,
            'new_data' => !empty($newData) ? json_encode($newData) : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }
}

namespace App\Core\Repositories\Decorators;

use App\Core\Repositories\Contracts\RepositoryInterface;
use App\Core\Repositories\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class AuditableRepository implements RepositoryInterface
{
    use Auditable;

    protected RepositoryInterface $repository;

    public function __construct(RepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function create(array $data): Model
    {
        $model = $this->repository->create($data);
        $this->logActivity($model, 'created', [], $data);
        return $model;
    }

    public function update(int $id, array $data): Model
    {
        $oldData = $this->repository->find($id)->toArray();
        $model = $this->repository->update($id, $data);
        $this->logActivity($model, 'updated', $oldData, $data);
        return $model;
    }

    public function delete(int $id): bool
    {
        $model = $this->repository->find($id);
        $oldData = $model->toArray();
        
        $result = $this->repository->delete($id);
        if ($result) {
            $this->logActivity($model, 'deleted', $oldData, []);
        }
        
        return $result;
    }

    // Delegate other methods to repository
    public function __call($method, $arguments)
    {
        return $this->repository->{$method}(...$arguments);
    }
}
