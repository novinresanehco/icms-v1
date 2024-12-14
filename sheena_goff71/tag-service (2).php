<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Contracts\TagRepositoryInterface;
use App\Core\Tag\Exceptions\TagException;
use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Core\Tag\Events\TagCreated;
use App\Core\Tag\Events\TagUpdated;
use App\Core\Tag\Events\TagsAttached;
use App\Core\Tag\Events\TagsMerged;
use App\Core\Services\BaseService;

class TagService extends BaseService
{
    /**
     * @var TagRepositoryInterface
     */
    protected TagRepositoryInterface $tagRepository;

    /**
     * @param TagRepositoryInterface $tagRepository
     */
    public function __construct(TagRepositoryInterface $tagRepository)
    {
        $this->tagRepository = $tagRepository;
    }

    /**
     * Create a new tag
     *
     * @param array $data
     * @return Tag
     * @throws TagException
     */
    public function createTag(array $data): Tag
    {
        // Validate input
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255|unique:tags,name',
            'slug' => 'nullable|string|max:255|unique:tags,slug',
            'description' => 'nullable|string|max:1000',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            throw new TagException('Invalid tag data: ' . $validator->errors()->first());
        }

        // Generate slug if not provided
        if (!isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        try {
            $tag = $this->tagRepository->create($data);
            
            // Dispatch event
            event(new TagCreated($tag));

            return $tag;
        } catch (\Exception $e) {
            throw new TagException("Failed to create tag: {$e->getMessage()}");
        }
    }

    /**
     * Update existing tag
     *
     * @param int $id
     * @param array $data
     * @return Tag
     * @throws TagException
     */
    public function updateTag(int $id, array $data): Tag
    {
        // Validate input
        $validator = Validator::make($data, [
            'name' => "required|string|max:255|unique:tags,name,{$id}",
            'slug' => "nullable|string|max:255|unique:tags,slug,{$id}",
            'description' => 'nullable|string|max:1000',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            throw new TagException('Invalid tag data: ' . $validator->errors()->first());
        }

        try {
            // Generate slug if name changed
            if (isset($data['name']) && !isset($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            $tag = $this->tagRepository->update($id, $data);
            
            // Dispatch event
            event(new TagUpdated($tag));

            return $tag;
        } catch (\Exception $e) {
            throw new TagException("Failed to update tag: {$e->getMessage()}");
        }
    }

    /**
     * Attach tags to content
     *
     * @param int $contentId
     * @param array $tagNames
     * @return Collection
     * @throws TagException
     */
    public function attachTagsToContent(int $contentId, array $tagNames): Collection
    {
        try {
            $tags = collect();
            $tagIds = [];

            foreach ($tagNames as $tagName) {
                // Find or create tag
                $tag = $this->tagRepository->findBySlug(Str::slug($tagName));
                
                if (!$tag) {
                    $tag = $this->createTag(['name' => $tagName]);
                }

                $tags->push($tag);
                $tagIds[] = $tag->id;
            }

            // Attach tags to content
            $this->tagRepository->attachToContent($contentId, $tagIds);

            // Dispatch event
            event(new TagsAttached($contentId, $tags));

            return $tags;
        } catch (\Exception $e) {
            throw new TagException("Failed to attach tags: {$e->getMessage()}");
        }
    }

    /**
     * Merge two tags
     *
     * @param int $sourceTagId
     * @param int $targetTagId
     * @return Tag
     * @throws TagException
     */
    public function mergeTags(int $sourceTagId, int $targetTagId): Tag
    {
        if ($sourceTagId === $targetTagId) {
            throw new TagException("Cannot merge a tag with itself");
        }

        try {
            $targetTag = $this->tagRepository->mergeTags($sourceTagId, $targetTagId);
            
            // Dispatch event
            event(new TagsMerged($sourceTagId, $targetTag));

            return $targetTag;
        } catch (\Exception $e) {
            throw new TagException("Failed to merge tags: {$e->getMessage()}");
        }
    }

    /**
     * Get tag suggestions based on content
     *
     * @param string $content
     * @param int $limit
     * @return Collection
     */
    public function suggestTagsForContent(string $content, int $limit = 5): Collection
    {
        // Use natural language processing to extract keywords
        $keywords = $this->extractKeywords($content);

        // Find matching tags
        return $this->tagRepository->getSuggestions(
            implode(' ', $keywords),
            $limit
        );
    }

    /**
     * Clean up unused tags
     *
     * @return int Number of deleted tags
     */
    public function cleanupUnusedTags(): int
    {
        try {
            return $this->tagRepository->cleanUnused();
        } catch (\Exception $e) {
            throw new TagException("Failed to cleanup unused tags: {$e->getMessage()}");
        }
    }

    /**
     * Extract keywords from content
     *
     * @param string $content
     * @return array
     */
    protected function extractKeywords(string $content): array
    {
        // Remove HTML tags
        $text = strip_tags($content);

        // Remove special characters
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        // Convert to lowercase
        $text = mb_strtolower($text);

        // Split into words
        $words = str_word_count($text, 1);

        // Remove common words
        $words = array_diff($words, $this->getCommonWords());

        // Sort by frequency
        $frequencies = array_count_values($words);
        arsort($frequencies);

        return array_keys(array_slice($frequencies, 0, 10));
    }

    /**
     * Get common words to exclude
     *
     * @return array
     */
    protected function getCommonWords(): array
    {
        return [
            'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have',
            'i', 'it', 'for', 'not', 'on', 'with', 'he', 'as', 'you',
            'do', 'at', 'this', 'but', 'his', 'by', 'from', 'they',
            'we', 'say', 'her', 'she', 'or', 'an', 'will', 'my', 'one',
            'all', 'would', 'there', 'their', 'what', 'so', 'up', 'out',
            'if', 'about', 'who', 'get', 'which', 'go', 'me'
        ];
    }
}
