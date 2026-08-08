<?php

namespace App\Http\Controllers;

use App\Models\Concept;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceResponse;

class ConceptController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $query = Concept::with('career');

            // Filtro por búsqueda (type, description, gestion)
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('type', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('gestion', 'like', "%{$search}%");
                });
            }

            // Filtro por carrera
            if ($request->filled('career_id')) {
                $query->where('career_id', $request->input('career_id'));
            }

            // Filtro por gestión
            if ($request->filled('gestion')) {
                $query->where('gestion', $request->input('gestion'));
            }

            // Paginación dinámica
            $perPage = $request->input('per_page', 10);
            $concepts = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json($concepts);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los conceptos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'career_id'  => 'required|integer|exists:careers,id',
                'type'       => 'required|string|in:Matricula,Mensualidad,Otro',
                'gestion'    => 'required|integer',
                'semestre'   => 'nullable|integer|in:1,2',
                'amount'     => 'required|numeric|min:0',
                'description'=> 'nullable|string',
            ]);

            $concept = Concept::create($validated);
            return response()->json([
                'concept_id' => $concept->id,
            ],200);

        } catch (Exception $e) {
            return response()->json([],500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Concept $concept)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Concept $concept)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Concept $concept)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Concept $concept)
    {
        try{
            $concept->delete();
            return response()->json(['message' => 'Concepto eliminado exitosamente'],200);
        }catch(Exception $e){
            return response()->json([],500);
        }
    }
}
