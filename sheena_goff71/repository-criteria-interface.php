<?php

namespace App\Core\Repository\Criteria;

use Illuminate\Database\Eloquent\Model;
use App\Core\Repository\Contracts\RepositoryInterface;

interface CriteriaInterface
{
    /**
     * Apply criteria to the model
     *
     * @param Model $model
     * @param RepositoryInterface $repository
     * @return mixed
     */
    public function apply(Model $model, RepositoryInterface $repository): mixed;
}
