<?php

namespace App\Core\Tag\Factory;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\DTO\TagData;
use App\Core\Shared\Factory\FactoryInterface;
use Illuminate\Support\Str;

class TagFactory implements FactoryInterface
{
    /**
     * Create a new Tag instance.
     *
     * @param TagData $data
     * @return Tag
     */
    public function create(TagData $data): Tag
    {
        // Validate data
        $errors = $data->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Invalid tag data: ' . json_encode($errors));
        }

        // Generate slug if not provided
        if (empty($data->slug)) {
            $data->slug = Str::slug($data->name);
        }

        // Create tag model
        $tag = new Tag([
            'name' => $data->name,
            'slug' => $data->slug,
            'description' => $data->description,
            'meta' => $data->meta,
            'is_active' => $data->isActive,
        ]);

        return $tag;
    }

    /**
     * Create Tag from array data.
     *
     * @param array $data
     * @return Tag
     */
    public function createFromArray(array $data): Tag
    {
        return $this->create(new TagData($data));
    }

    /**
     * Create multiple Tags from array data.
     *
     * @param array $dataArray
     * @return array
     */
    public function createMany(array $dataArray): array
    {
        return array_map(
            fn($data) => $this->createFromArray($data),
            $dataArray
        );
    }

    /**
     * Create Tag from string (name only).
     *
     * @param string $name
     * @return Tag
     */
    public function createFromString(string $name): Tag
    {
        return $this->createFromArray(['name' => $name]);
    }
}
