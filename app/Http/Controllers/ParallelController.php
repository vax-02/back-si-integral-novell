<?php

namespace App\Http\Controllers;

use App\Models\Parallel;
use Exception;
use Illuminate\Http\Request;

class ParallelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try{
            $parallels = Parallel::where('course_id',$request->courseId)->get();
            $totalCapacity = $parallels->sum('limit'); // O el nombre que tenga el campo

            return response()->json([
                'summary' => [
                    'total_students' => 0,
                    'total_capacity' => $totalCapacity,
                ],
                'parallels' => $parallels
            ]);
        }catch(Exception $e){
            return response()->json([],500);
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
        try{
            $validated = $request->validate([
                'course_id' => ['required', 'integer', 'exists:courses,id'],
                'parallel' => ['required', 'string', 'max:2'],
                'limit' => ['required', 'integer', 'min:1', 'max:100'],
                'turno' => ['required', 'in:Mañana,Tarde,Noche']
            ]);

            $existingParallel = Parallel::where('course_id', $request->course_id)
                ->where('paralelo', $request->parallel)
                ->first();

            if ($existingParallel) {
                return response()->json([
                    'error' => 'Ya existe un paralelo con esa letra para este curso',
                    'parallel' => $request->parallel
                ], 422);
            }
            $parallel = Parallel::create([
                'course_id' => $request->course_id,
                'paralelo' => $request->parallel,
                'limit' => $request->limit,
                'turno' => $request->turno
            ]);
            
            return response()->json([
                'message' => 'Paralelo creado correctamente',
                'parallel' => $parallel
            ], 201);
            
        }catch(Exception $e){
            return response()->json([
                'error' => 'Error al crear el paralelo',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Parallel $parallel)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Parallel $parallel)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Parallel $parallel)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Parallel $parallel)
    {
        //
    }
}
