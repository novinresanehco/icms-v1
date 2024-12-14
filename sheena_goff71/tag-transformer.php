<?php

namespace App\Core\Tag\Services\Transformers;

use App\Core\Tag\Services\Actions\DTOs\{TagActionResponse, TagBulkActionResponse};
use App\Core\Tag\Models\Tag;

class TagResponseTransformer
{
    public function transformActionResponse(TagActionResponse $response): array
    {
        return [
            'success' => $response->success,
            'message' => $response->message,
            'data' => $this->transformData($response->data),
            'errors' => $response->errors,
            'meta' => $response->meta
        ];
    }

    public function transformBulkActionResponse(TagBulkActionResponse $response): array
    {
        return [
            'success' => $response->success,
            'results' => $this->transformBulkResults($response->results),
            'errors' => $response->errors,
            'meta' => $response->meta
        ];
    }

    protected function transformData(array $data): array
    {
        if (isset($data['tag']) && $data['tag'] instanceof Tag) {
            $data['tag'] = $this->transformTag($data['tag']);
        }

        return $data;
    }

    protected function transformTag(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'description' => $tag->description,
            'metadata' => $tag->metadata,
            'parent_id' => $tag->parent_id,
            'created_at' => $tag->created_at->toISOString(),
            'updated_at' => $tag->updated_at->toISOString()
        ];
    }

    protected function transformBulkResults(array $results): array
    {
        return collect($results)->map(function ($result) {
            if ($result instanceof Tag) {
                return $this->transformTag($result);
            }
            return $result;
        })->toArray();
    }
}
