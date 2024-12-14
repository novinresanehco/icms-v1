<?php

namespace App\Core\Tag\Events;

use App\Core\Tag\Models\Tag;

class TagCreated
{
    public function __construct(public readonly Tag $tag)
    {
    }
}

class TagUpdated
{
    public function __construct(public readonly Tag $tag)
    {
    }
}

class TagDeleted
{
    public function __construct(public readonly Tag $tag)
    {
    }
}

class TagsReordered
{
    public function __construct(public readonly array $orderedTags)
    {
    }
}

class TagAttached
{
    public function __construct(
        public readonly Tag $tag,
        public readonly string $attachableType,
        public readonly int $attachableId
    ) {
    }
}

class TagDetached
{
    public function __construct(
        public readonly Tag $tag,
        public readonly string $attachableType,
        public readonly int $attachableId
    ) {
    }
}
