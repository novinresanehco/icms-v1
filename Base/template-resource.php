<?php

namespace App\Core\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'content' => $this->content,
            'type' => $this->type,
            'category' => $this->category,
            'status' => $this->status,
            'variables' => $this->variables,
            'settings' => $this->settings,
            'version' => $this->version,
            'regions' => RegionResource::collection($this->whenLoaded('regions')),
            'author' => new UserResource($this->whenLoaded('author')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}
