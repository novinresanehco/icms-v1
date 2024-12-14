// app/Core/Widget/Middleware/WidgetAuthorizationMiddleware.php
<?php

namespace App\Core\Widget\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Widget\Services\WidgetAuthorizationService;
use App\Core\Widget\Exceptions\WidgetUnauthorizedException;

class WidgetAuthorizationMiddleware
{
    public function __construct(private WidgetAuthorizationService $authService)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $widget = $request->route('widget');
        $user = $request->user();

        if (!$this->authService->canAccess($user, $widget)) {
            throw new WidgetUnauthorizedException();
        }

        return $next($request);
    }
}

// app/Core/Widget/Middleware/WidgetCacheMiddleware.php
<?php

namespace App\Core\Widget\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WidgetCacheMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $widget = $request->route('widget');
        
        if ($widget->cache_ttl) {
            $cacheKey = "widget:{$widget->id}:rendered";
            
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
            
            $response = $next($request);
            
            Cache::put($cacheKey, $response, $widget->cache_ttl);
            
            return $response;
        }
        
        return $next($request);
    }
}

// app/Core/Widget/Validators/WidgetValidator.php
<?php

namespace App\Core\Widget\Validators;

use App\Core\Widget\DTO\WidgetData;
use Illuminate\Support\Facades\Validator;
use App\Core\Widget\Exceptions\WidgetValidationException;

class WidgetValidator
{
    public function validate(array $data): void
    {
        $validator = Validator::make($data, [
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
        ]);

        if ($validator->fails()) {
            throw new WidgetValidationException($validator->errors()->toArray());
        }
    }
}

// app/Core/Widget/Validators/WidgetVisibilityValidator.php
<?php

namespace App\Core\Widget\Validators;

use Illuminate\Support\Facades\Validator;
use App\Core\Widget\Exceptions\WidgetValidationException;

class WidgetVisibilityValidator
{
    public function validate(array $rules): void
    {
        $validator = Validator::make($rules, [
            'conditions' => 'required|array',
            'conditions.*.type' => 'required|string|in:role,permission,custom',
            'conditions.*.value' => 'required|string',
            'operator' => 'required|string|in:and,or'
        ]);

        if ($validator->fails()) {
            throw new WidgetValidationException($validator->errors()->toArray());
        }
    }
}

// app/Core/Widget/Validators/WidgetOrderValidator.php
<?php

namespace App\Core\Widget\Validators;

use Illuminate\Support\Facades\Validator;
use App\Core\Widget\Exceptions\WidgetValidationException;

class WidgetOrderValidator
{
    public function validate(array $order): void
    {
        $validator = Validator::make($order, [
            '*' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            throw new WidgetValidationException($validator->errors()->toArray());
        }
    }
}

// app/Core/Widget/Services/WidgetAuthorizationService.php
<?php

namespace App\Core\Widget\Services;

use App\Core\Widget\Models\Widget;
use Illuminate\Contracts\Auth\Authenticatable;

class WidgetAuthorizationService
{
    public function canAccess(?Authenticatable $user, Widget $widget): bool
    {
        if (empty($widget->permissions)) {
            return true;
        }

        if (!$user) {
            return false;
        }

        foreach ($widget->permissions as $permission) {
            if (!$this->checkPermission($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function canManage(?Authenticatable $user, Widget $widget): bool
    {
        if (!$user) {
            return false;
        }

        return $user->hasRole('admin') || 
               $user->can('manage_widgets') || 
               $user->id === $widget->created_by;
    }

    private function checkPermission(Authenticatable $user, array $permission): bool
    {
        return match($permission['type']) {
            'role' => $user->hasRole($permission['value']),
            'permission' => $user->can($permission['value']),
            'custom' => $this->checkCustomRule($user, $permission['value']),
            default => false
        };
    }

    private function checkCustomRule(Authenticatable $user, string $rule): bool
    {
        return app(WidgetRuleEvaluator::class)->evaluate($user, $rule);
    }
}

// app/Core/Widget/Services/WidgetRuleEvaluator.php
<?php

namespace App\Core\Widget\Services;

use Illuminate\Contracts\Auth\Authenticatable;

class WidgetRuleEvaluator
{
    public function evaluate(Authenticatable $user, string $rule): bool
    {
        $ruleInstance = $this->resolveRule($rule);
        return $ruleInstance->evaluate($user);
    }

    private function resolveRule(string $rule): WidgetRuleInterface
    {
        $ruleClass = "App\\Core\\Widget\\Rules\\{$rule}Rule";
        
        if (!class_exists($ruleClass)) {
            throw new \RuntimeException("Widget rule not found: {$rule}");
        }
        
        return new $ruleClass();
    }
}

// app/Core/Widget/Contracts/WidgetRuleInterface.php
<?php

namespace App\Core\Widget\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface WidgetRuleInterface
{
    public function evaluate(Authenticatable $user): bool;
}