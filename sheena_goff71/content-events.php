<?php

namespace App\Core\Content\Events;

use App\Core\Content\Models\Content;

class ContentCreated
{
    public function __construct(public readonly Content $content)
    {
    }
}

class ContentUpdated
{
    public function __construct(public readonly Content $content)
    {
    }
}

class ContentDeleted
{
    public function __construct(public readonly Content $content)
    {
    }
}

class ContentStatusChanged
{
    public function __construct(
        public readonly Content $content,
        public readonly string $newStatus,
        public readonly ?string $oldStatus = null
    ) {
    }
}

class ContentRevisionCreated
{
    public function __construct(
        public readonly Content $content,
        public readonly Content $revision
    ) {
    }
}

class ContentRestored
{
    public function __construct(
        public readonly Content $content,
        public readonly int $revisionId
    ) {
    }
}

class ContentTagsChanged
{
    public function __construct(
        public readonly Content $content,
        public readonly array $addedTagIds,
        public readonly array $removedTagIds
    ) {
    }
}

class ContentCategoriesChanged
{
    public function __construct(
        public readonly Content $content,
        public readonly array $addedCategoryIds,
        public readonly array $removedCategoryIds
    ) {
    }
}

class ContentMediaChanged
{
    public function __construct(
        public readonly Content $content,
        public readonly array $addedMediaIds,
        public readonly array $removedMediaIds
    ) {
    }
}
