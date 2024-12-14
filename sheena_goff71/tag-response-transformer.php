<?php

namespace App\Core\Tag\Services\Transformers;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use App\Core\Tag\Services\Actions\DTOs\{TagActionResponse, TagBulkActionResponse};

class TagResponseTransformer
{
    /**
     * Transform a tag model to array.
     */
    public function transform(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'description' => $tag->description,
            'meta_title' => $tag->meta_title,
            'meta_description' => $tag->meta_description,
            'usage_count' => $tag->contents_count ?? 0,
            'created_at' => $tag->created_at->toISOString(),
            'updated_at' => $tag->updated_at->toISOString(),
            'metadata' => $this->transformMetadata($tag),
            'relationships' => $this->transformRelationships($tag),
            '_links' => $this->generateLinks($tag)
        ];
    }

    /**
     * Transform a collection of tags.
     */
    public function transformCollection(Collection $tags): array
    {
        return $tags->map(fn(Tag $tag) => $this->transform($tag))->toArray();
    }

    /**
     * Transform action response.
     */
    public function transformActionResponse(TagActionResponse $response): array
    {
        return array_filter([
            'success' => $response->success,
            'message' => $response->message,
            'error' => $response->error,
            'data' => isset($response->data['tag']) ? 
                $this->transform($response->data['tag']) : 
                $response->data,
            'metadata' => $response->metadata
        ]);
    }

    /**
     * Transform bulk action response.
     */
    public function transformBulkActionResponse(TagBulkActionResponse $response): array
    {
        $transformedResults = [];
        foreach ($response->results as $tagId => $result) {
            $transformedResults[$tagId] = $result instanceof Tag ? 
                $this->transform($result) : 
                $result;
        }

        return [
            'success' => $response->success,
            'results' => $transformedResults,
            'failures' => $response->failures,
            'metadata' => $response->metadata,
            'stats' => [
                'total' => $response->getSuccessCount() + $response->getFailureCount(),
                'successful' => $response->getSuccessCount(),
                'failed' => $response->getFailureCount()
            ]
        ];
    }

    /**
     * Transform tag metadata.
     */
    protected function transformMetadata(Tag $tag): array
    {
        return [
            'author' => $tag->metadata['author_id'] ?? null,
            'visibility' => $tag->metadata['visibility'] ?? 'public',
            'expires_at' => $tag->metadata['expires_at'] ?? null,
            'custom_fields' => $tag->metadata['custom_fields'] ?? []
        ];
    }

    /**
     * Transform tag relationships.
     */
    protected function transformRelationships(Tag $tag): array
    {
        return [
            'contents' => $tag->relationLoaded('contents') ? 
                $tag->contents->pluck('id')->toArray() : [],
            'parents' => $tag->relationLoaded('parents') ? 
                $tag->parents->pluck('id')->toArray() : [],
            'children' => $tag->relationLoaded('children') ? 
                $tag->children->pluck('id')->toArray() : []
        ];
    }

    /**
     * Generate HATEOAS links.
     */
    protected function generateLinks(Tag $tag): array
    {
        return [
            'self' => route('api.tags.show', $tag->id),
            'contents' => route('api.tags.contents', $tag->id),
            'update' => route('api.tags.update', $tag->id),
            'delete' => route('api.tags.destroy', $tag->id)
        ];
    }
}
