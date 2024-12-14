<?php

namespace App\Core\Events;

use App\Core\Models\Category;
use Illuminate\Queue\SerializesModels;

class CategoryCreated
{
    use SerializesModels;

    public Category $category;

    public function __construct(Category $category)
    {
        $this->category = $category;
    }
}
