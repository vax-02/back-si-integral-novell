<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\Docente;
use App\Models\Parallel;
use Illuminate\Http\Request;
use Exception;

class SubjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Subject::query()->with(['career', 'prerequisite']);

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }

        if ($request->filled('sigla')) {
            $query->where('sigla', 'like', '%' . $request->input('sigla') . '%');
        }

        if ($request->filled('career')) {
            $query->whereHas('career', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->input('career') . '%');
            });
        }

        $subjects = $query->orderBy('name')->paginate($request->input('per_page', 10));

        return response()->json([
            'subjects' => $subjects,
        ]);
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Subject $subject)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Subject $subject)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Subject $subject)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Subject $subject)
    {
        //
    }

    // ─────────────────────────────────────────────────────────────
    //  DETAIL  GET /api/subjects/{subject}/detail
    //  Devuelve los paralelos donde se dicta la materia + docentes asignados
    // ─────────────────────────────────────────────────────────────
    public function detail(Subject $subject)
    {
        try {
            // Paralelos donde esta materia aparece en schedules
            $parallels = Parallel::whereHas('schedules', function ($q) use ($subject) {
                $q->where('subject_id', $subject->id);
            })->with(['course.career'])->get();

            $parallelsById = $parallels->keyBy('id');

            // Docentes activos asignados a esta materia (con su paralelo)
            $docentes = $subject->activeDocentes()
                ->with(['user', 'degree'])
                ->get()
                ->map(function ($docente) use ($parallelsById) {
                    $parallel = $parallelsById->get($docente->pivot->parallel_id);
                    return [
                        'id'           => $docente->id,
                        'name'         => $docente->user->name . ' ' . $docente->user->first_lastname,
                        'email'        => $docente->user->email,
                        'parallel_id'  => $docente->pivot->parallel_id,
                        'parallel_name' => $parallel ? $parallel->paralelo : '—',
                        'parallel_turno' => $parallel ? $parallel->turno : '—',
                        'course_name'  => $parallel && $parallel->course ? $parallel->course->name : '—',
                        'status'       => $docente->pivot->status,
                        'created_at'   => $docente->pivot->created_at,
                    ];
                });

            return response()->json([
                'subject'   => $subject->load('career'),
                'parallels' => $parallels,
                'docentes'  => $docentes,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  HISTORY  GET /api/subjects/{subject}/history
    //  Historial completo de asignaciones docentes (activas e inactivas)
    // ─────────────────────────────────────────────────────────────
    public function history(Subject $subject)
    {
        try {
            $parallels = Parallel::whereHas('schedules', function ($q) use ($subject) {
                $q->where('subject_id', $subject->id);
            })->with(['course.career'])->get()->keyBy('id');

            $history = $subject->docentes()
                ->with(['user', 'degree'])
                ->orderBy('docente_subject.created_at', 'desc')
                ->get()
                ->map(function ($docente) use ($parallels) {
                    $parallel = $parallels->get($docente->pivot->parallel_id);
                    return [
                        'id'            => $docente->id,
                        'name'          => $docente->user->name . ' ' . $docente->user->first_lastname,
                        'email'         => $docente->user->email,
                        'parallel_id'   => $docente->pivot->parallel_id,
                        'parallel_name' => $parallel ? $parallel->paralelo : '—',
                        'parallel_turno' => $parallel ? $parallel->turno : '—',
                        'course_name'   => $parallel && $parallel->course ? $parallel->course->name : '—',
                        'status'        => (bool) $docente->pivot->status,
                        'created_at'    => $docente->pivot->created_at,
                        'updated_at'    => $docente->pivot->updated_at,
                    ];
                });

            return response()->json([
                'history' => $history,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  ASSIGN DOCENTE  POST /api/subjects/{subject}/assign-docente
    //  Asigna un docente a una materia y paralelo específico
    // ─────────────────────────────────────────────────────────────
    public function assignDocente(Request $request, Subject $subject)
    {
        $request->validate([
            'docente_id'  => 'required|integer|exists:docentes,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
        ]);

        try {
            $docenteId  = $request->docente_id;
            $parallelId = $request->parallel_id;

            // Buscar si ya existe un registro con status 0 para reactivar
            $existing = $subject->docentes()
                ->wherePivot('docente_id', $docenteId)
                ->wherePivot('parallel_id', $parallelId)
                ->first();

            if ($existing) {
                // Reactivar
                $subject->docentes()->updateExistingPivot($docenteId, [
                    'status'      => true,
                    'parallel_id' => $parallelId,
                    'updated_at'  => now(),
                ]);
            } else {
                // Crear nuevo
                $subject->docentes()->attach($docenteId, [
                    'parallel_id' => $parallelId,
                    'status'      => true,
                ]);
            }

            return response()->json([
                'message' => 'Docente asignado correctamente.',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  REMOVE DOCENTE  POST /api/subjects/{subject}/remove-docente
    //  Marca status=0 (baja lógica)
    // ─────────────────────────────────────────────────────────────
    public function removeDocente(Request $request, Subject $subject)
    {
        $request->validate([
            'docente_id'  => 'required|integer|exists:docentes,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
        ]);

        try {
            $subject->docentes()->updateExistingPivot($request->docente_id, [
                'status'     => false,
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => 'Docente desasignado correctamente.',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
