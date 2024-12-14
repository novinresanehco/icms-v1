<?php

namespace App\Core\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'type' => $this->type,
            'status' => $this->status,
            'order' => $this->order,
            'settings' => $this->settings,
            'path' => $this->path,
            'meta' => MetaResource::collection($this->whenLoaded('meta')),
            'children' => static::collection($this->whenLoaded('children')),
            'parent' => new static($this->whenLoaded('parent')),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString()
        ];
    }
}
