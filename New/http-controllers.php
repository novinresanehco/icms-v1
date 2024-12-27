<?php

namespace App\Http\Controllers;

use App\Core\Services\{AuthService, UserService, ContentService, MediaService};
use App\Core\Security\SecurityContext;

class AuthController extends Controller
{
    private AuthService $auth;
    
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->authenticate($request->validated());
        return new JsonResponse(['token' => $result->token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->bearerToken());
        return new JsonResponse(['status' => 'success']);
    }
}

class UserController extends Controller 
{
    private UserService $users;
    
    public function store(StoreUserRequest $request): JsonResponse
    {
        $context = SecurityContext::fromRequest($request);
        $user = $this->users->create($request->validated(), $context);
        return new JsonResponse($user, 201);
    }
    
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $context = SecurityContext::fromRequest($request);
        $user = $this->users->update($id, $request->validated(), $context);
        return new JsonResponse($user);
    }
    
    public function destroy(Request $request, int $id): JsonResponse  
    {
        $context = SecurityContext::fromRequest($request);
        $this->users->delete($id, $context);
        return new JsonResponse(['status' => 'success']);
    }
}

class ContentController extends Controller
{
    private ContentService $content;
    
    public function store(StoreContentRequest $request): JsonResponse
    {
        $context = SecurityContext::fromRequest($request);
        $content = $this->content->store($request->validated(), $context);
        return new JsonResponse($content, 201);
    }
    
    public function update(UpdateContentRequest $request, int $id): JsonResponse
    {
        $context = SecurityContext::fromRequest($request);
        $content = $this->content->update($id, $request->validated(), $context);
        return new JsonResponse($content);
    }
    
    public function destroy(Request $request, int $id): JsonResponse
    {
        $context = SecurityContext::fromRequest($request);
        $this->content->delete($id, $context);
        return new JsonResponse(['status' => 'success']);
    }
}

class MediaController extends Controller
{
    private MediaService $media;
    
    public function store(StoreMediaRequest $request): JsonResponse
    {
        $context = SecurityContext::fromRequest($request);
        $media = $this->media->store($request->file('media'), $context);
        return new JsonResponse($media, 201);
    }
    
    public function destroy(Request $request, int $id): JsonResponse
    {
        $context = SecurityContext::fromRequest($request);
        $this->media->delete($id, $context);
        return new JsonResponse(['status' => 'success']);
    }
}

class TemplateController extends Controller 
{
    private TemplateEngine $templates;
    
    public function render(RenderTemplateRequest $request): JsonResponse
    {
        $context = SecurityContext::fromRequest($request);
        $html = $this->templates->render($request->template, $request->data);
        return new JsonResponse(['html' => $html]);
    }
}
