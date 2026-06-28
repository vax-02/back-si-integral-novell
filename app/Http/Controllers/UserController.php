<?php

namespace App\Http\Controllers;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $user = User::with('role')->where('email', $validated['email'])->first();

            if (! $user || ! Hash::check($validated['password'], $user->password)) {
                return response()->json([
                    'message' => 'Invalid credentials.',
                ], 401);
            }

            if ((int) $user->status !== 1) {
                return response()->json([
                    'message' => 'User is inactive.',
                ], 403);
            }

            $expiresAt = now()->addHours(8);
            $basicUserData = [
                'id' => $user->id,
                'role_id' => $user->role_id,
                'role' => $user->role?->name,
                'ci' => $user->ci,
                'name' => $user->name,
                'first_lastname' => $user->first_lastname,
                'second_lastname' => $user->second_lastname,
                'email' => $user->email,
                'cellphone' => $user->cellphone,
                'status' => $user->status,
            ];

            $token = Crypt::encryptString(json_encode([
                'user' => $basicUserData,
                'issued_at' => now()->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
            ]));

            return response()->json([
                'message' => 'Login successful.',
                'token_type' => 'Bearer',
                'token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => $basicUserData,
            ]);
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function index()
    {
        try {
            return response()->json(User::with('role')->get());
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return response()->json([
            'message' => 'Method not allowed. Use POST /api/users.',
        ], 405);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'ci' => ['required', 'string', 'max:12', 'unique:users,ci'],
            'name' => ['required', 'string', 'max:255'],
            'first_lastname' => ['required', 'string', 'max:255'],
            'second_lastname' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'cellphone' => ['nullable', 'string', 'max:8'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
        ]);

        try {
            $user = User::create($validated);

            return response()->json([
                'message' => 'User created successfully.',
                'data' => $user->load('role'),
            ], 201);
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        try {
            return response()->json($user->load('role'));
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        return response()->json([
            'message' => 'Method not allowed. Use PUT/PATCH /api/users/'.$user->id.'.',
        ], 405);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'role_id' => ['sometimes', 'required', 'integer', 'exists:roles,id'],
            'ci' => [
                'sometimes',
                'required',
                'string',
                'max:12',
                Rule::unique('users', 'ci')->ignore($user->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'first_lastname' => ['sometimes', 'required', 'string', 'max:255'],
            'second_lastname' => ['nullable', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
            'cellphone' => ['nullable', 'string', 'max:8'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
        ]);

        try {
            $user->update($validated);

            return response()->json([
                'message' => 'User updated successfully.',
                'data' => $user->load('role'),
            ]);
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        try {
            $user->delete();

            return response()->json([
                'message' => 'User deleted successfully.',
            ]);
        } catch (Exception $exception) {
            return $this->errorResponse($exception);
        }
    }

    private function errorResponse(Exception $exception)
    {
        report($exception);

        return response()->json([
            'message' => 'An error occurred while processing the request.',
        ], 500);
    }
}
