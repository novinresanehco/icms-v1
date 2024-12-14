<?php

namespace App\Core\Category\Events;

use App\Core\Category\Models\Category;

class CategoryCreated
{
    public function __construct(public readonly Category $category)
    {
    }
}

class CategoryUpdated
{
    public function __construct(public readonly Category $category)
    {
    }
}

class CategoryDeleted
{
    public function __construct(public readonly Category $category)
    {
    }
}

class CategoryMoved
{
    public function __construct(
        public readonly Category $category,
        public readonly ?int $oldParentId,
        public readonly ?int $newParentId
    ) {
    }
}

class CategoryTreeReordered
{
    public function __construct(public readonly array $order)
    {
    }
}

class CategoryContentAssigned
{
    public function __construct(
        public readonly Category $category,
        public readonly array $contentIds
    ) {
    }
}

class CategoryContentUnassigned
{
    public function __construct(
        public readonly Category $category,
        public readonly array $contentIds
    ) {
    }
}
