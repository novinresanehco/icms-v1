// app/Core/Widget/Controllers/Api/WidgetController.php
<?php

namespace App\Core\Widget\Controllers\Api;

use App\Core\Widget\Services\WidgetService;
use App\Core\Widget\Resources\WidgetResource;
use App\Core\Widget\Requests\CreateWidgetRequest;
use App\Core\Widget\Requests\UpdateWidgetRequest;
use App\Core\Widget\Requests\UpdateWidgetOrderRequest;
use App\Core\Widget\Requests\UpdateWidgetVisibilityRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

class WidgetController
{
    public function __construct(private WidgetService $widgetService)
    {
    }

    public function index(): ResourceCollection
    {
        $widgets = $this->widgetService->getAllWidgets();
        return WidgetResource::collection($widgets);
    }

    public function store(CreateWidgetRequest $request): JsonResponse
    {
        $widget = $this->widgetService->createWidget($request->validated());
        return (new WidgetResource($widget))
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $id): WidgetResource
    {
        $widget = $this->widgetService->getWidget($id);
        return new WidgetResource($widget);
    }

    public function update(UpdateWidgetRequest $request, int $id): WidgetResource
    {
        $widget = $this->widgetService->updateWidget($id, $request->validated());
        return new WidgetResource($widget);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->widgetService->deleteWidget($id);
        return response()->json(null, 204);
    }

    public function updateOrder(UpdateWidgetOrderRequest $request): JsonResponse
    {
        $this->widgetService->updateWidgetOrder($request->validated());
        return response()->json(['message' => 'Widget order updated successfully']);
    }

    public function updateVisibility(
        UpdateWidgetVisibilityRequest $request, 
        int $id
    ): JsonResponse {
        $this->widgetService->updateWidgetVisibility($id, $request->validated());
        return response()->json(['message' => 'Widget visibility updated successfully']);
    }
}

// app/Core/Widget/Requests/CreateWidgetRequest.php
<?php

namespace App\Core\Widget\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create_widgets');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'identifier' => 'required|string|max:255|regex:/^[a-z0-9\-_]+$/|unique:widgets,identifier',
            'type' => 'required|string|max:50',
            'area' => 'required|string|max:50',
            'settings' => 'sometimes|array',
            'order' => 'nullable|integer',
            'is_active' => 'boolean',
            'cache_ttl' => 'nullable|integer|min:0',
            'visibility_rules' => 'sometimes|array',
            'permissions' => 'sometimes|array',
            'metadata' => 'nullable|array'
        ];
    }
}

// app/Core/Widget/Requests/UpdateWidgetRequest.php
<?php

namespace App\Core\Widget\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('edit_widgets');
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'identifier' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9\-_]+$/',
                Rule::unique('widgets')->ignore($this->route('id'))
            ],
            'type' => 'sometimes|required|string|max:50',
            'area' => 'sometimes|required|string|max:50',
            'settings' => 'sometimes|array',
            'order' => 'nullable|integer',
            'is_active' => 'boolean',
            'cache_ttl' => 'nullable|integer|min:0',
            'visibility_rules' => 'sometimes|array',
            'permissions' => 'sometimes|array',
            'metadata' => 'nullable|array'
        ];
    }
}

// app/Core/Widget/Requests/UpdateWidgetOrderRequest.php
<?php

namespace App\Core\Widget\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWidgetOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_widgets');
    }

    public function rules(): array
    {
        return [
            'order' => 'required|array',
            'order.*' => 'required|integer|min:0'
        ];
    }
}

// app/Core/Widget/Requests/UpdateWidgetVisibilityRequest.php
<?php

namespace App\Core\Widget\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWidgetVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_widgets');
    }

    public function rules(): array
    {
        return [
            'conditions' => 'required|array',
            'conditions.*.type' => 'required|string|in:role,permission,custom',
            'conditions.*.value' => 'required|string',
            'operator' => 'required|string|in:and,or'
        ];
    }
}

// app/Core/Widget/Resources/WidgetResource.php
<?php

namespace App\Core\Widget\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WidgetResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'identifier' => $this->identifier,
            'type' => $this->type,
            'area' => $this->area,
            'settings' => $this->settings,
            'order' => $this->order,
            'is_active' => $this->is_active,
            'cache_ttl' => $this->cache_ttl,
            'visibility_rules' => $this->visibility_rules,
            'permissions' => $this->permissions,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}