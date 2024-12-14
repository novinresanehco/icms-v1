<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Menu extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'location',
        'description',
        'settings',
        'is_active'
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean'
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('order');
    }

    public function activeItems(): HasMany
    {
        return $this->hasMany(MenuItem::class)
            ->where('is_active', true)
            ->orderBy('order');
    }

    public function getStructuredItems(): array
    {
        return $this->buildItemsTree($this->items);
    }

    protected function buildItemsTree($items, $parentId = null): array
    {
        $branch = [];
        
        foreach ($items as $item) {
            if ($item->parent_id === $parentId) {
                $children = $this->buildItemsTree($items, $item->id);
                if ($children) {
                    $item->children = $children;
                }
                $branch[] = $item;
            }
        }
        
        return $branch;
    }
}
