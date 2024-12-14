<?php

namespace App\Services;

use App\Events\CommentCreated;
use App\Events\CommentMarkedAsSpam;
use App\Repositories\Contracts\CommentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommentService
{
    protected CommentRepositoryInterface $commentRepository;
    
    public function __construct(CommentRepositoryInterface $commentRepository)
    {
        $this->commentRepository = $commentRepository;
    }
    
    public function createComment(array $data): ?int
    {
        $this->validateCommentData($data);
        
        $commentId = $this->commentRepository->create($data);
        
        if ($commentId) {
            Event::dispatch(new CommentCreated($commentId));
        }
        
        return $commentId;
    }
    
    public function updateComment(int $commentId, array $data): bool
    {
        $this->validateCommentData($data);
        return $this->commentRepository->update($commentId, $data);
    }
    
    public function deleteComment(int $commentId): bool
    {
        return $this->commentRepository->delete($commentId);
    }
    
    public function getComment(int $commentId): ?array
    {
        return $this->commentRepository->get($commentId);
    }
    
    public function getContentComments(int $contentId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->commentRepository->getForContent($contentId, $perPage);
    }
    
    public function getRecentComments(int $limit = 10): Collection
    {
        return $this->commentRepository->getRecent($limit);
    }
    
    public function approveComment(int $commentId): bool
    {
        return $this->commentRepository->approve($commentId);
    }
    
    public function rejectComment(int $commentId): bool
    {
        return $this->commentRepository->reject($commentId);
    }
    
    public function markAsSpam(int $commentId): bool
    {
        $result = $this->commentRepository->markAsSpam($commentId);
        
        if ($result) {
            Event::dispatch(new CommentMarkedAsSpam($commentId));
        }
        
        return $result;
    }
    
    public function getUnapprovedComments(int $perPage = 15): LengthAwarePaginator
    {
        return $this->commentRepository->getUnapproved($perPage);
    }
    
    public function getSpamComments(int $perPage = 15): LengthAwarePaginator
    {
        return $this->commentRepository->getSpam($perPage);
    }
    
    public function replyToComment(int $parentId, array $data): ?int
    {
        $this->validateCommentData($data);
        return $this->commentRepository->replyTo($parentId, $data);
    }
    
    protected function validateCommentData(array $data): void
    {
        $validator = Validator::make($data, [
            'content_id' => 'sometimes|required|exists:contents,id',
            'user_id' => 'nullable|exists:users,id',
            'content' => 'required|string|max:1000',
            'approved' => 'boolean'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
