<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface RepositoryInterface
{
    /**
     * Get all records
     *
     * @param array $columns
     * @param array $relations
     * @return Collection
     */
    public function all(array $columns = ['*'], array $relations = []): Collection;

    /**
     * Get all records with pagination
     *
     * @param int $perPage
     * @param array $columns
     * @param array $relations
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $columns = ['*'], array $relations = []): LengthAwarePaginator;

    /**
     * Find record by ID
     *
     * @param int $modelId
     * @param array $columns
     * @param array $relations
     * @param array $appends
     * @return Model|null
     */
    public function findById(
        int $modelId,
        array $columns = ['*'],
        array $relations = [],
        array $appends = []
    ): ?Model;

    /**
     * Find record by custom field
     *
     * @param string $field
     * @param mixed $value
     * @param array $columns
     * @param array $relations
     * @return Model|null
     */
    public function findByField(
        string $field,
        mixed $value,
        array $columns = ['*'],
        array $relations = []
    ): ?Model;

    /**
     * Find multiple records by field
     *
     * @param string $field
     * @param array $values
     * @param array $columns
     * @param array $relations
     * @return Collection
     */
    public function findManyByField(
        string $field,
        array $values,
        array $columns = ['*'],
        array $relations = []
    ): Collection;

    /**
     * Create new record
     *
     * @param array $payload
     * @return Model
     */
    public function create(array $payload): Model;

    /**
     * Update existing record
     *
     * @param int $modelId
     * @param array $payload
     * @return Model
     */
    public function update(int $modelId, array $payload): Model;

    /**
     * Delete record
     *
     * @param int $modelId
     * @return bool
     */
    public function deleteById(int $modelId): bool;

    /**
     * Begin database transaction
     *
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * Commit database transaction
     *
     * @return void
     */
    public function commit(): void;

    /**
     * Rollback database transaction
     *
     * @return void
     */
    public function rollBack(): void;
}
