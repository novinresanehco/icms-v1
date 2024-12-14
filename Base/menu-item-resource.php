<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'menu_id' => $this->menu_id,
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'url' => $this->url,
            'target' => $this->target,
            'icon' => $this->icon,
            'class' => $this->class,
            'order' => $this->order,
            'conditions' => $this->conditions,
            'is_active' => $this->is_active,
            'children' => MenuItemResource::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
