<?php

namespace App\Repositories\Contracts;

interface MediaRepositoryInterface
{
    public function find(int $id);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function getByContent(int $contentId);
    public function attachToContent(int $mediaId, int $contentId, array $attributes = []);
    public function detachFromContent(int $mediaId, int $contentId);
    public function updateContentAssociation(int $mediaId, int $contentId, array $attributes);
}
