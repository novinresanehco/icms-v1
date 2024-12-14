<?php

namespace App\Repositories\Contracts;

interface CommentRepositoryInterface
{
    public function find(int $id);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function getByContent(int $contentId);
    public function getByUser(int $userId);
    public function approve(int $id);
    public function reject(int $id);
    public function spam(int $id);
    public function getAwaitingModeration();
    public function getReplies(int $commentId);
}
