<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class MenuController extends Controller
{
    protected MenuService $menuService;

    public function __construct(MenuService $menuService)
    {
        $this->menuService = $menuService;
    }

    public function index(): JsonResponse
    {
        $menus = $this->menuService->getAllMenus();
        return response()->json($menus);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $menuId = $this->menuService->createMenu($request->all());
            return response()->json(['id' => $menuId], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function show(int $id): JsonResponse
    {
        $menu = $this->menuService->getMenu($id);
        
        if (!$menu) {
            return response()->json(['message' => 'Menu not found'], Response::HTTP_NOT_FOUND);
        }
        
        return response()->json($menu);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $success = $this->menuService->updateMenu($id, $request->all());
            
            if (!$success) {
                return response()->json(['message' => 'Menu not found'], Response::HTTP_NOT_FOUND);
            }
            
            return response()->json(['message' => 'Menu updated successfully']);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $success = $this->menuService->deleteMenu($id);
        
        if (!$success) {
            return response()->json(['message' => 'Menu not found'], Response::HTTP_NOT_FOUND);
        }
        
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
