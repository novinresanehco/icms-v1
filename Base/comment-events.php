<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $commentId;

    public function __construct(int $commentId)
    {
        $this->commentId = $commentId;
    }
}

class CommentMarkedAsSpam
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $commentId;

    public function __construct(int $comment