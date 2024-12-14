<?php

namespace App\Core\Tag\Events;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Queue\SerializesModels;

class TagCreated
{
    use SerializesModels;

    /**
     * @var Tag
     */
    public Tag $tag;

    /**
     * @param Tag $tag
     */
    public function __construct(Tag $tag)
    {
        $this->tag = $tag;
    }
}

class TagUpdated
{
    use SerializesModels;

    /**
     * @var Tag
     */
    public Tag $tag;

    /**
     * @param Tag $tag
     */
    public function __construct(Tag $tag)
    {
        $this->tag = $tag;
    }
}

class TagsAttached
{
    use SerializesModels;

    /**
     * @var int
     */
    public int $contentId;

    /**
     * @var Collection
     */
    public Collection $tags;

    /**
     * @param int $contentId
     * @param Collection $tags
     */
    public function __construct(int $contentId, Collection $tags)
    {
        $this->contentId = $contentId;
        $this->tags = $tags;
    }
}

class TagsMerged
{
    use SerializesModels;

    /**
     * @var int
     */
    public int $sourceTagId;

    /**
     * @var Tag
     */
    public Tag $targetTag;

    /**
     * @param int $sourceTagId
     * @param Tag $targetTag
     */
    public function __construct(int $sourceTagId, Tag $targetTag)
    {
        $this->sourceTagId = $sourceTagId;
        $this->targetTag = $targetTag;
    }
}
