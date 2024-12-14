<?php

namespace App\Core\Content\Contracts;

interface ContentRepositoryInterface
{
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id);
    public function find($id);
    public function findBySlug(string $slug);
    public function getByStatus(string $status, array $filters = []);
    public function paginateWithFilters(array $filters, int $perPage = 15);
}

interface ContentServiceInterface
{
    public function create(array $data