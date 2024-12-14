<?php

namespace App\Http\Controllers;

class CriticalController extends Controller
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    
    protected function executeSecure(callable $operation)
    {
        try {
            $context = $this->security->validateRequest(request());
            return $operation($context);
        } catch (SecurityException $e) {
            return $this->handleSecurityFailure($e);
        } catch (ValidationException $e) {
            return $this->handleValidationFailure($e);
        }
    }
}

class ContentController extends CriticalController
{
    private ContentManagementService $content;

    public function store(ContentRequest $request)
    {
        return $this->executeSecure(function($context) use ($request) {
            $content = $this->content->create($request->validated());
            return new ContentResource($content);
        });
    }

    public function publish(int $id)
    {
        return $this->executeSecure(function($context) use ($id) {
            $this->content->publish($id);
            return response()->json(['status' => 'published']);
        });
    }
}

namespace App\Http\Middleware;

class CriticalSecurityMiddleware
{
    private SecurityManager $security;
    
    public function handle($request, $next)
    {
        $this->security->validateRequest($request);
        
        $response = $next($request);
        
        $this->security->validateResponse($response);
        
        return $response;
    }
}
