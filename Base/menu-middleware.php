<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MenuAdminMiddleware
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (!$request->user() || !$request->user()->hasPermission('manage_menus')) {
            return response()->json(
                ['error' => 'Unauthorized to manage menus'], 
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
