<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class ValidateEncryptedToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'message' => 'Authentication token is required.',
            ], 401);
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (Exception) {
            return response()->json([
                'message' => 'Invalid authentication token.',
            ], 401);
        }

        if (! is_array($payload) || empty($payload['user']['id']) || empty($payload['expires_at'])) {
            return response()->json([
                'message' => 'Invalid authentication token.',
            ], 401);
        }

        if (now()->greaterThan($payload['expires_at'])) {
            return response()->json([
                'message' => 'Authentication token has expired.',
            ], 401);
        }

        $user = User::find($payload['user']['id']);

        if (! $user || (int) $user->status !== 1) {
            return response()->json([
                'message' => 'Invalid authentication token.',
            ], 401);
        }

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_token_payload', $payload);

        return $next($request);
    }
}
