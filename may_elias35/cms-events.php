<?php

namespace App\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContentStatusUpdated
{
    use Dispatchable, SerializesModels;

    public $content;

    public function __construct($content)
    {
        $this->content = $content;
    }
}

class TagsAttachedToContent
{
    use Dispatchable, SerializesModels;

    public $contentId;
    public $tagIds;

    public function __construct(int $contentId, array $tagIds)
    {
        $this->contentId = $contentId;
        $this->tagIds = $tagIds;
    }
}

class MediaAttachedToContent
{
    use Dispatchable, SerializesModels;

    public $contentId;
    public $mediaIds;

    public function __construct(int $contentId, array $mediaIds)
    {
        $this->contentId = $contentId;
        $this->mediaIds = $mediaIds;
    }
}

trait EventDispatcherTrait
{
    protected function dispatchDomainEvent($event): void
    {
        event($event);
    }

    protected function dispatchQueuedEvent($event): void
    {
        event(new QueuedEvent($event));
    }
}

class QueuedEvent
{
    use Dispatchable, SerializesModels;

    public $event;

    public function __construct($event)
    {
        $this->event = $event;
    }
}
