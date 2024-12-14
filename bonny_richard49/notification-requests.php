<?php

namespace App\Core\Notification\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'type' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(['read', 'unread', 'all'])],
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'sort' => ['nullable', Rule::in(['created_at', 'read_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])]
        ];
    }
}

class MarkNotificationsReadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'notifications' => 'required|array|min:1',
            'notifications.*' => [
                'required',
                'string',
                'uuid',
                Rule::exists('notifications', 'id')->where(function ($query) {
                    $query->where('notifiable_id', $this->user()->id)
                          ->where('notifiable_type', get_class($this->user()));
                })
            ]
        ];
    }
}

class DeleteNotificationsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'notifications' => 'required|array|min:1',
            'notifications.*' => [
                'required',
                'string',
                'uuid',
                Rule::exists('notifications', 'id')->where(function ($query) {
                    $query->where('notifiable_id', $this->user()->id)
                          ->where('notifiable_type', get_class($this->user()));
                })
            ]
        ];
    }
}

class UpdatePreferencesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'preferences' => 'required|array',
            'preferences.*.channel' => [
                'required',
                'string',
                Rule::in(config('notifications.available_channels', []))
            ],
            'preferences.*.enabled' => 'required|boolean',
            'preferences.*.settings' => 'nullable|array',
            'preferences.*.settings.*' => 'nullable|string'
        ];
    }
}

class UpdateChannelPreferenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
            'settings' => 'nullable|array',
            'settings.*' => 'nullable|string'
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!in_array($this->route('channel'), config('notifications.available_channels', []))) {
                $validator->errors()->add('channel', 'Invalid notification channel.');
            }
        });
    }
}

class CreateTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage-notification-templates');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100|unique:notification_templates',
            'type' => 'required|string|max:50',
            'channels' => 'required|array|min:1',
            'channels.*' => [
                'required',
                'string',
                Rule::in(config('notifications.available_channels', []))
            ],
            'content' => 'required|array',
            'content.*' => 'required|array',
            'content.*.subject' => 'required|string|max:200',
            'content.*.body' => 'required|string',
            'content.*.template' => 'nullable|string',
            'metadata' => 'nullable|array',
            'metadata.*' => 'nullable|string',
            'active' => 'nullable|boolean'
        ];
    }
}

class UpdateTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage-notification-templates');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('notification_templates')->ignore($this->route('template'))
            ],
            'type' => 'sometimes|required|string|max:50',
            'channels' => 'sometimes|required|array|min:1',
            'channels.*' => [
                'required',
                'string',
                Rule::in(config('notifications.available_channels', []))
            ],
            'content' => 'sometimes|required|array',
            'content.*' => 'required|array',
            'content.*.subject' => 'required|string|max:200',
            'content.*.body' => 'required|string',
            'content.*.template' => 'nullable|string',
            'metadata' => 'nullable|array',
            'metadata.*' => 'nullable|string',
            'active' => 'nullable|boolean'
        ];
    }
}

class PreviewTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage-notification-templates');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'channel' => [
                'required',
                'string',
                Rule::in(config('notifications.available_channels', []))
            ],
            'data' => 'required|array',
            'data.*' => 'nullable|string'
        ];
    }
}