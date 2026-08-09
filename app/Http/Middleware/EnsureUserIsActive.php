<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Bloquea el acceso si la cuenta del usuario está inhabilitada.
     * Revoca los tokens vigentes para cortar sesiones ya iniciadas.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        if ((int) $user->status !== 1) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Tu cuenta está inactiva.',
                'code' => 'INACTIVO',
            ], 403);
        }

        return $next($request);
    }
}
