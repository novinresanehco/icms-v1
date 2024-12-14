<?php

namespace App\Core\Contracts;

interface RepositoryInterface
{
    public function find(int $id);
    public function findOrFail(int $id);
    public function all();
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function paginate(int $perPage = 15);
}

interface VersionedRepositoryInterface extends RepositoryInterface
{
    public function createVersion(int $id, array $data);
    public function getVersions(int $id);
    public function revertToVersion(int $id, int $versionId);
}

interface SearchableRepositoryInterface extends RepositoryInterface
{
    public function search(string $query, array $options = []);
}

interface CacheableRepositoryInterface extends RepositoryInterface
{
    public function clearCache();
    public function disableCache();
    public function enableCache();
}
