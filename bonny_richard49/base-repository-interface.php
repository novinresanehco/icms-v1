<?php

namespace App\Core\Repository\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use App\Core\Repository\Criteria\CriteriaInterface;

interface RepositoryInterface
{
    /**
     * Find a model by its primary key.
     *
     * @param int|string $id
     * @return Model|null
     */
    public function find($id): ?Model;

    /**
     * Find a model by specific criteria.
     *
     * @param array $criteria
     * @return Model|null
     */
    public function findBy(array $criteria): ?Model;

    /**
     * Get all records.
     *
     * @return Collection
     */
    public function all(): Collection;

    /**
     * Create a new record.
     *
     * @param array $data
     * @return Model
     */
    public function create(array $data): Model;

    /**
     * Update an existing record.
     *
     * @param int|string $id
     * @param array $data
     * @return Model
     */
    public function update($id, array $data): Model;

    /**
     * Delete a record.
     *
     * @param int|string $id
     * @return bool
     */
    public function delete($id): bool;

    /**
     * Apply criteria to the query.
     *
     * @param CriteriaInterface ...$criteria
     * @return self
     */
    public function withCriteria(CriteriaInterface ...$criteria): self;

    /**
     * Begin a database transaction.
     *
     * @return self
     */
    public function beginTransaction(): self;

    /**
     * Commit the database transaction.
     *
     * @return self
     */
    public function commit(): self;

    /**
     * Rollback the database transaction.
     *
     * @return self
     */
    public function rollback(): self;

    /**
     * Add cache parameters.
     *
     * @param string $key
     * @param int|null $ttl
     * @return self
     */
    public function cache(string $key, ?int $ttl = null): self;
}
