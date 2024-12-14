<?php

namespace App\Core\Validators;

use App\Core\Exceptions\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

abstract class BaseValidator
{
    protected array $rules = [];
    protected array $messages = [];
    protected array $attributes = [];

    public function validate(array $data, ?Model $model = null): void
    {
        $rules = $this->getRules($model);
        $validator = Validator::make($data, $rules, $this->messages, $this->attributes);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $this->afterValidation($data, $model);
    }

    protected function getRules(?Model $model = null): array
    {
        return $this->rules;
    }

    protected function afterValidation(array $data, ?Model $model = null): void
    {
    }
}

class ContentTitleValidator extends BaseValidator
{
    protected array $rules = [
        'title' => 'required|string|min:3|max:255',
        'meta_title' => 'nullable|string|max:100',
        'description' => 'nullable|string|max:500'
    ];

    protected array $messages = [
        'title.required' => 'Content title is required',
        'title.min' => 'Title must be at least :min characters',
        'meta_title.max' => 'Meta title cannot exceed :max characters'
    ];
}

class ContentSlugValidator extends BaseValidator
{
    protected function getRules(?Model $model = null): array
    {
        $uniqueRule = 'unique:contents,slug';
        if ($model) {
            $uniqueRule .= ',' . $model->id;
        }

        return [
            'slug' => ['required', 'string', 'max:255', $uniqueRule]
        ];
    }

    protected function afterValidation(array $data, ?Model $model = null): void
    {
        if (!isset($data['slug']) && isset($data['title'])) {
            $data['slug'] = Str::slug($data['title']);
        }
    }
}

class ContentCategoryValidator extends BaseValidator
{
    protected array $rules = [
        'category_id' => 'required|exists:categories,id',
        'parent_id' => 'nullable|exists:contents,id'
    ];

    protected function afterValidation(array $data, ?Model $model = null): void
    {
        if (isset($data['parent_id']) && $model && $data['parent_id'] == $model->id) {
            throw new ValidationException('Content cannot be its own parent');
        }
    }
}

class ContentStatusValidator extends BaseValidator
{
    protected array $rules = [
        'status' => 'required|in:draft,published,archived'
    ];

    protected function afterValidation(array $data, ?Model $model = null): void
    {
        if (isset($data['status']) && $data['status'] === 'published') {
            if (!isset($data['published_at'])) {
                $data['published_at'] = now();
            }
        }
    }
}

class ContentMediaValidator extends BaseValidator
{
    protected array $rules = [
        'featured_image' => 'nullable|image|max:2048|dimensions:min_width=100,min_height=100',
        'gallery' => 'nullable|array',
        'gallery.*' => 'image|max:2048'
    ];

    protected array $messages = [
        'featured_image.dimensions' => 'Featured image must be at least 100x100 pixels',
        'featured_image.max' => 'Featured image may not be greater than 2MB'
    ];
}

class TagValidator extends BaseValidator
{
    protected array $rules = [
        'tags' => 'nullable|array',
        'tags.*' => 'exists:tags,id'
    ];
}

class MetadataValidator extends BaseValidator
{
    protected array $rules = [
        'metadata' => 'nullable|array',
        'metadata.seo_title' => 'nullable|string|max:100',
        'metadata.seo_description' => 'nullable|string|max:200',
        'metadata.og_image' => 'nullable|string|max:255',
        'metadata.robots' => 'nullable|string|in:index,noindex,follow,nofollow'
    ];
}

class VersionValidator extends BaseValidator
{
    protected array $rules = [
        'version' => 'nullable|integer',
        'changelog' => 'required_with:version|string|max:500'
    ];

    protected function afterValidation(array $data, ?Model $model = null): void
    {
        if (isset($data['version']) && $model && $data['version'] <= $model->version) {
            throw new ValidationException('New version must be greater than current version');
        }
    }
}
