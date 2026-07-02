<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use Illuminate\Http\Request;

class DocenteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->per_page ?? 10;

            $docentes = Docente::with(['user', 'degree'])->paginate($perPage);
            return response()->json(['docentes' => $docentes], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Error al listar los docentes.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        try {
            return response()->json(['message' => 'Use POST to create a new docente']);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Error al cargar el formulario de creación.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id|unique:docentes,user_id',
                'degree_id' => 'required|exists:degrees,id',
                'cv' => 'sometimes|in:0,1',
                'professional_title' => 'sometimes|in:0,1',
                'carnet' => 'sometimes|in:0,1',
                'certificate' => 'sometimes|in:0,1',
                'status' => 'sometimes|in:0,1',
            ]);

            $docente = Docente::create($validated);
            return response()->json($docente->load(['user', 'degree']), 201);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Error al crear el docente.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Docente $docente)
    {
        try {
            return $docente->load(['user', 'degree']);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Error al mostrar el docente.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Docente $docente)
    {
        try {
            return $docente->load(['user', 'degree']);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Error al cargar la edición del docente.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Docente $docente)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'sometimes|exists:users,id|unique:docentes,user_id,' . $docente->id,
                'degree_id' => 'sometimes|exists:degrees,id',
                'cv' => 'sometimes|in:0,1',
                'professional_title' => 'sometimes|in:0,1',
                'carnet' => 'sometimes|in:0,1',
                'certificate' => 'sometimes|in:0,1',
                'status' => 'sometimes|in:0,1',
            ]);

            $docente->update($validated);
            return response()->json($docente->load(['user', 'degree']), 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Error al actualizar el docente.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Docente $docente)
    {
        try {
            $docente->delete();
            return response()->json(['message' => 'Docente deleted successfully'], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Error al eliminar el docente.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
