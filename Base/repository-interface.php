<?php

namespace App\Repositories\Contracts;

interface RepositoryInterface
{
    /**
     * Find a model by its primary key.
     *
     * @param int|string $id
     * @param array<string> $relations
     * @return mixed
     */
    public function find(int|string $id, array $relations = []);

    /**
     * Find a model by specific criteria.
     *
     * @param array<string,mixed> $criteria
     * @param array<string> $relations
     * @return mixed
     */
    public function findBy(array $criteria, array $relations = []);

    /**
     * Get all records.
     *
     * @param array<string> $relations
     * @param array<string,string> $orderBy
     * @return mixed
     */
    public function all(array $relations = [], array $orderBy = ['id' => 'asc']);

    /**
     * Create a new record.
     *
     * @param array<string,mixed> $data
     * @return mixed
     */
    public function create(array $data);

    /**
     * Update an existing record.
     *
     * @param int|string $id
     * @param array<string,mixed> $data
     * @return mixed
     */
    public function update(int|string $id, array $data);

    /**
     * Delete a record.
     *
     * @param int|string $id
     * @return bool
     */
    public function delete(int|string $id): bool;

    /**
     * Get paginated results.
     *
     * @param int $perPage
     * @param array<string> $relations
     * @param array<string,string> $orderBy
     * @return mixed
     */
    public function paginate(int $perPage = 15, array $relations = [], array $orderBy = ['id' => 'asc']);
}
