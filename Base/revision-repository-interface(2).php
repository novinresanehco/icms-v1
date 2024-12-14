<?php

namespace App\Repositories\Contracts;

interface RevisionRepositoryInterface
{
    public function find(int $id);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function delete(int $id);
    public function getByContent(int $contentId);
    public function getByUser(int $userId);
    public function compare(int $revisionId1, int $revisionId2);
    public function restore(int $revisionId);
    public function getLatestByContent(int $contentId);
}
