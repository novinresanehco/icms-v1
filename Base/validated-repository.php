<?php

namespace App\Core\Repositories\Decorators;

use App\Core\Repositories\Contracts\RepositoryInterface;
use App\Core\Repositories\Validation\AbstractValidator;
use Illuminate\Database\Eloquent\Model;

class ValidatedRepository implements RepositoryInterface
{
    protected RepositoryInterface $repository;
    protected AbstractValidator $validator;

    public function __construct(RepositoryInterface $repository, AbstractValidator $validator)
    {
        $this->repository = $repository;
        $this->validator = $validator;
    }

    public function create(array $data): Model
    {
        $validated = $this->validator->validate($data);
        return $this->repository->create($validated);
    }

    public function update(int $id, array $data): Model
    {
        $validated = $this->validator->validate(array_merge($data, ['id' => $id]));
        return $this->repository->update($id, $validated);
    }

    // Delegate other methods to repository
    public function __call($method, $arguments)
    {
        return $this->repository->{$method}(...$arguments);
    }
}
