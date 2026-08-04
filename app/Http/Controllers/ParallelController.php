<?php

namespace App\Http\Controllers;

use App\Models\Course;
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
            //$parallels = Parallel::where('course_id',$request->courseId)->get();
            //$parallels = Parallel::where('course_id', $request->courseId)->withCount('students')->get();
            $parallels = Parallel::where('course_id', $request->courseId)
                ->withCount([
                    'students as students_count' => function ($query) {
                        $query->where('status', true);
                        // o ->where('status', 1);
                    }
                ])
                ->get();
            $totalCapacity = $parallels->sum('limit');

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

    public function getFirstCourse($id)
    {
        try {
            $course = Course::where('career_id', $id)
                ->where('level', 1)
                ->first();

            if (!$course) {
                return response()->json([
                    'message' => 'No se encontró un curso de nivel 1 para esta carrera.'
                ], 404);
            }
            $parallels = Parallel::where('course_id', $course->id)->where('status',1)->get();
            return response()->json([
                'parallels' => $parallels
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error interno del servidor.',
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
        try{
            $validated = $request->validate([
                'course_id' => ['required', 'integer', 'exists:courses,id'],
                'parallel' => ['required', 'string', 'max:2'],
                'limit' => ['required', 'integer', 'min:1', 'max:100'],
                'turno' => ['required', 'in:Mañana,Tarde,Noche']
            ]);

            //Validar para cambiar limite
            $p = Parallel::withCount([
                'students as students_count' => function ($query) {
                $query->where('status', true);
            }])->findOrFail($parallel->id);

            if($p->students_count <= $request->limit){
                $parallel->course_id = $request->course_id;
                $parallel->paralelo = $request->parallel;
                $parallel->limit = $request->limit;
                $parallel->turno = $request->turno;
                $parallel->save();
            }else{
                return response()->json(['message' => 'No se puede reducir el cupo'],422);
            }
            return response()->json(['message' => 'Paralelo actualizado'],200);

        }catch(Exception $e){
            return response()->json(
                ['data' => null,
                'error' => $e],
            500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Parallel $parallel)
    {
        //
    }

    public function toggleStatus(Parallel $parallel)
    {
        try {
            if($parallel->status){
                //validar que no haya estudiantes
                $p = Parallel::withCount([
                    'students as students_count' => function ($query) {
                    $query->where('status', true);
                }])->findOrFail($parallel->id);

                if($p->students_count > 0){
                    return response()->json([
                        'message' => 'El paralelo no puede modificarse.'
                    ],422);
                }
            }

            $parallel->status = $parallel->status ? 0 : 1;
            $parallel->save();

            return response()->json([
                'status' => $parallel->status,
                'message' => $parallel->status ? 'Paralelo activada correctamente.' : 'Paralelo desactivada correctamente.',
            ],200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'No se pudo cambiar el estado del paralelo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
