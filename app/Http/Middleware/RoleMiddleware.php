<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Verifica que el usuario autenticado tenga al menos uno de los roles permitidos.
     *
     * Uso: ->middleware('role:1,3,4')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        $userRoleIds = $user->roles()
            ->pluck('roles.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $allowedRoleIds = array_map('intval', $roles);

        if (!array_intersect($userRoleIds, $allowedRoleIds)) {
            return response()->json([
                'message' => 'No tiene permisos para acceder a este recurso.',
            ], 403);
        }

        return $next($request);
    }
}
