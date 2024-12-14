<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string|min:8'
        ];
    }
}

class ContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $rules = [
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'status' => 'required|in:draft,published',
            'category_id' => 'required|exists:categories,id',
            'media_ids.*' => 'exists:media,id'
        ];

        if ($this->isMethod('PUT')) {
            $rules = array_map(function($rule) {
                return str_replace('required|', '', $rule);
            }, $rules);
        }

        return $rules;
    }
}

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'permissions' => $this->permissions->pluck('permission'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}

class ContentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'media' => MediaResource::collection($this->whenLoaded('media')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}

class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'contents_count' => $this->when(isset($this->contents_count), $this->contents_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}

class MediaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'url' => url($this->path),
            'uploaded_by' => new UserResource($this->whenLoaded('uploader')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}

namespace App\Http\Responses;

class ApiResponse
{
    public static function success($data = null, string $message = 'Success', int $code = 200): array
    {
        return [
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ];
    }

    public static function error(string $message, int $code = 400, $errors = null): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'errors' => $errors,
            'code' => $code
        ];
    }
}
