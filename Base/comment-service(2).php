<?php

namespace App\Core\Services;

use App\Core\Repositories\{
    CommentRepository,
    CommentModerationRepository,
    CommentFlagRepository,
    CommentSubscriptionRepository
};
use App\Core\Events\{CommentCreated, CommentModerated, CommentFlagged};
use App\Core\Exceptions\CommentException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Event, Notification};

class CommentService extends BaseService
{
    protected CommentModerationRepository $moderationRepository;
    protected CommentFlagRepository $flagRepository;
    protected CommentSubscriptionRepository $subscriptionRepository;

    public function __construct(
        CommentRepository $repository,
        CommentModerationRepository $moderationRepository,
        CommentFlagRepository $flagRepository,
        CommentSubscriptionRepository $subscriptionRepository
    ) {
        parent::__construct($repository);
        $this->moderationRepository = $moderationRepository;
        $this->flagRepository = $flagRepository;
        $this->subscriptionRepository = $subscriptionRepository;
    }

    public function createComment(Model $content, array $attributes): Model
    {
        try {
            DB::beginTransaction();

            $comment = $this->repository->createComment($content, $attributes);

            $this->notifySubscribers($content, $comment);

            DB::commit();

            return $comment;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CommentException("Failed to create comment: {$e->getMessage()}", 0, $e);
        }
    }

    public function moderateComment(Model $comment, string $status): bool
    {
        try {
            DB::beginTransaction();

            $moderated = $this->repository->updateStatus($comment, $status);

            if ($moderated) {
                Event::dispatch(new CommentModerated($comment));

                if ($status === 'approved') {
                    $this->notifyAuthor($comment);
                }
            }

            DB::commit();

            return $moderated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CommentException("Failed to moderate comment: {$e->getMessage()}", 0, $e);
        }
    }

    public function flagComment(Model $comment, string $reason): Model
    {
        try {
            DB::beginTransaction();

            $flag = $this->flagRepository->flagComment($comment, $reason);

            Event::dispatch(new CommentFlagged($comment, $reason));

            DB::commit();

            return $flag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new CommentException("Failed to flag comment: {$e->getMessage()}", 0, $e);
        }
    }

    public function subscribe(Model $content): Model
    {
        try {
            return $this->subscriptionRepository->subscribe($content, auth()->id());
        } catch (\Exception $e) {
            throw new CommentException("Failed to subscribe: {$e->getMessage()}", 0, $e);
        }
    }

    public function unsubscribe(Model $content): bool
    {
        try {
            return $this->subscriptionRepository->unsubscribe($content, auth()->id());
        } catch (\Exception $e) {
            throw new CommentException("Failed to unsubscribe: {$e->getMessage()}", 0, $e);
        }
    }

    public function getPendingComments(): Collection
    {
        return $this->moderationRepository->getPendingComments();
    }

    public function getFlaggedComments(): Collection
    {
        return $this->moderationRepository->getFlaggedComments();
    }

    protected function notifySubscribers(Model $content, Model $comment): void
    {
        $subscribers = $this->subscriptionRepository->getSubscribers($content);

        Notification::send($subscribers, new NewCommentNotification($comment));
    }

    protected function notifyAuthor(Model $comment): void
    {
        if ($comment->author) {
            $comment->author->notify(new CommentApprovedNotification($comment));
        }
    }
}
