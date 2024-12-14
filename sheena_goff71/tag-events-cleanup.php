<?php

namespace App\Core\Tag\Events;

use Illuminate\Support\Collection;
use Illuminate\Queue\SerializesModels;

class TagsCleanedUp
{
    use SerializesModels;

    /**
     * @var Collection
     */
    public Collection $removedTags;

    /**
     * Create a new event instance.
     */
    public function __construct(Collection $removedTags)
    {
        $this->removedTags = $removedTags;
    }
}

class TagsNormalized
{
    use SerializesModels;

    /**
     * @var int
     */
    public int $normalizedCount;

    /**
     * Create a new event instance.
     */
    public function __construct(int $normalizedCount)
    {
        $this->normalizedCount = $normalizedCount;
    }
}

class InvalidRelationshipsFixed
{
    use SerializesModels;

    /**
     * @var int
     */
    public int $fixedCount;

    /**
     * Create a new event instance.
     */
    public function __construct(int $fixedCount)
    {
        $this->fixedCount = $fixedCount;
    }
}
