<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Carbon;

interface ActivityRepositoryInterface
{
    /**
     * Log a new activity
     */
    public function log(
        string $description,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = []
    ): Model;

    /**
     * Get activities for a specific subject
     */
    public function forSubject(Model $subject): Collection;

    /**
     * Get activities by specific type
     */
    public function getByType(string $type): Collection;

    /**
     * Get activities within a date range
     */
    public function getInDateRange(Carbon $startDate, Carbon $endDate): Collection;

    /**
     * Clean up old activities
     */
    public function cleanup(int $daysToKeep): bool;

    /**
     * Get activities by causer
     */
    public function getByCauser(Model $causer): Collection;

    /**
     * Get a paginated list of all activities
     */
    public function getPaginated(int $perPage = 15): Collection;
}
