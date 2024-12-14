<?php

namespace App\Core\Content\Factory;

use App\Core\Content\Models\Content;
use App\Core\Content\DTO\ContentData;
use App\Core\Shared\Factory\FactoryInterface;
use Illuminate\Support\Str;

class ContentFactory implements FactoryInterface
{
    /**
     * Create a new Content instance.
     *
     * @param ContentData $data
     * @return Content
     */
    public function create(ContentData $data): Content
    {
        // Create content model
        $content = new Content([
            'title' => $data->title,
            'slug' => Str::slug($data->title),
            'content' => $data->content,
            'excerpt' => $data->excerpt ?? $this->generateExcerpt($data->content),
            'status' => $data->status ?? 'draft',
            'category_id' => $data->categoryId,
            'author_id' => $data->authorId,
            'published_at' => $data->publishedAt,
            'is_featured' => $data->isFeatured ?? false,
            'meta_title' => $data->metaTitle ?? $data->title,
            'meta_description' => $data->metaDescription ?? $this->generateMetaDescription($data->content),
            'meta_keywords' => $data->metaKeywords ?? ''
        ]);

        return $content;
    }

    /**
     * Create Content from array data.
     *
     * @param array $data
     * @return Content
     */
    public function createFromArray(array $data): Content
    {
        return $this->create(new ContentData($data));
    }

    /**
     * Generate excerpt from content.
     *
     * @param string $content
     * @param int $length
     * @return string
     */
    protected function generateExcerpt(string $content, int $length = 160): string
    {
        $plainText = strip_tags($content);
        $excerpt = Str::limit($plainText, $length, '...');
        return $excerpt;
    }

    /**
     * Generate meta description from content.
     *
     * @param string $content
     * @param int $length
     * @return string
     */
    protected function generateMetaDescription(string $content, int $length = 160): string
    {
        return $this->generateExcerpt($content, $length);
    }
}
