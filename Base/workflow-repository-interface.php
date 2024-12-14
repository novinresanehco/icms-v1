<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Support\Collection;

interface WorkflowRepositoryInterface extends RepositoryInterface
{
    public function createWorkflow(string $type,