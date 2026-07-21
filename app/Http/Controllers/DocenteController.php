<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use App\Models\User;
use App\Models\UserRoles;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DocenteController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    //  INDEX  GET /api/docentes
    // ─────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search  = $request->input('search');

            $query = Docente::with(['user', 'degree', 'subjects.career']);

            if ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name',           'like', "%$search%")
                      ->orWhere('first_lastname','like', "%$search%")
                      ->orWhere('second_lastname','like', "%$search%")
                      ->orWhere('ci',            'like', "%$search%")
                      ->orWhere('email',         'like', "%$search%");
                });
            }

            $total    = Docente::count();
            $activos = Docente::whereHas('user', function ($query) {
                $query->where('status', 1);
            })->count();

            $inactivos = Docente::whereHas('user', function ($query) {
                $query->where('status', 0);
            })->count();
            return response()->json([
                'docentes'  => $query->paginate($perPage),
                'total'     => $total,
                'activos'   => $activos,
                'inactivos' => $inactivos,
            ]);
        } catch (Exception $e) {
            return response()->json([],500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            // Datos del usuario
            'name'             => ['required', 'string', 'max:255'],
            'first_lastname'   => ['required', 'string', 'max:255'],
            'second_lastname'  => ['nullable', 'string', 'max:255'],
            'ci'               => ['required', 'string', 'max:12', 'unique:users,ci'],
            'email'            => ['required', 'email', 'max:255', 'unique:users,email'],
            'cellphone'        => ['nullable', 'string', 'max:8'],
            // Datos del docente
            'degree_id'        => ['required', 'integer', 'exists:degrees,id'],
            'cv'               => ['sometimes', 'boolean'],
            'professional_title' => ['sometimes', 'boolean'],
            'carnet'           => ['sometimes', 'boolean'],
            'certificate'      => ['sometimes', 'boolean'],
        ]);

        try {
            DB::beginTransaction();

            $user = User::create([
                'name'           => $request->name,
                'first_lastname' => $request->first_lastname,
                'second_lastname'=> $request->second_lastname,
                'ci'             => $request->ci,
                'email'          => $request->email,
                'cellphone'      => $request->cellphone,
                'password'       => Hash::make($request->ci), // contraseña por defecto: el CI
            ]);

            UserRoles::create([
                'user_id' => $user->id,
                'role_id' => 3
            ]);
            $docente = Docente::create([
                'user_id'           => $user->id,
                'degree_id'         => $request->degree_id,
                'cv'                => $request->boolean('cv', false),
                'professional_title'=> $request->boolean('professional_title', false),
                'carnet'            => $request->boolean('carnet', false),
                'certificate'       => $request->boolean('certificate', false),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Docente creado exitosamente.',
                'data'    => $docente->load(['user', 'degree', 'subjects']),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([],500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  SHOW  GET /api/docentes/{docente}
    // ─────────────────────────────────────────────────────────────
    public function show(Docente $docente)
    {
        try {
            return response()->json($docente->load(['user', 'degree', 'subjects.career']));
        } catch (Exception $e) {
            return response()->json([],500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  UPDATE  PUT /api/docentes/{docente}
    // ─────────────────────────────────────────────────────────────
    public function update(Request $request, Docente $docente)
    {
        $request->validate([
            'name'             => ['sometimes', 'string', 'max:255'],
            'first_lastname'   => ['sometimes', 'string', 'max:255'],
            'second_lastname'  => ['nullable',  'string', 'max:255'],
            'ci'               => ['sometimes', 'string', 'max:12', 'unique:users,ci,' . $docente->user_id],
            'email'            => ['sometimes', 'email',  'max:255', 'unique:users,email,' . $docente->user_id],
            'cellphone'        => ['nullable',  'string', 'max:8'],
            'degree_id'        => ['sometimes', 'integer', 'exists:degrees,id'],
            'cv'               => ['sometimes', 'boolean'],
            'professional_title' => ['sometimes', 'boolean'],
            'carnet'           => ['sometimes', 'boolean'],
            'certificate'      => ['sometimes', 'boolean'],
        ]);

        try {
            DB::beginTransaction();

            // Actualizar datos del usuario
            $userFields = array_filter([
                'name'           => $request->name,
                'first_lastname' => $request->first_lastname,
                'second_lastname'=> $request->second_lastname,
                'ci'             => $request->ci,
                'email'          => $request->email,
                'cellphone'      => $request->cellphone,
            ], fn($v) => !is_null($v));

            if (!empty($userFields)) {
                $docente->user->update($userFields);
            }

            // Actualizar datos del docente
            $docenteFields = array_filter([
                'degree_id'         => $request->degree_id,
                'cv'                => $request->has('cv')                 ? $request->boolean('cv')                 : null,
                'professional_title'=> $request->has('professional_title') ? $request->boolean('professional_title') : null,
                'carnet'            => $request->has('carnet')             ? $request->boolean('carnet')             : null,
                'certificate'       => $request->has('certificate')        ? $request->boolean('certificate')        : null,
            ], fn($v) => !is_null($v));

            if (!empty($docenteFields)) {
                $docente->update($docenteFields);
            }

            DB::commit();

            return response()->json([
                'message' => 'Docente actualizado exitosamente.',
                'data'    => $docente->fresh(['user', 'degree', 'subjects']),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([],500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  TOGGLE STATUS  PUT /api/docentes/{docente}/toggle-status
    // ─────────────────────────────────────────────────────────────
    public function toggleStatus(Docente $docente)
    {
        try {
            $newStatus = $docente->status ? 0 : 1;

            DB::beginTransaction();
            $docente->update(['status' => $newStatus]);
            $docente->user->update(['status' => $newStatus]);
            DB::commit();

            return response()->json([
                'message' => $newStatus ? 'Docente activado.' : 'Docente bloqueado.',
                'status'  => $newStatus,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([],500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  ASSIGN SUBJECT  POST /api/docentes/{docente}/subjects
    // ─────────────────────────────────────────────────────────────
    public function assignSubject(Request $request, Docente $docente)
    {
        $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
        ]);

        try {
            // syncWithoutDetaching evita duplicar, solo añade si no existe
            $docente->subjects()->syncWithoutDetaching([$request->subject_id]);

            return response()->json([
                'message'  => 'Materia asignada exitosamente.',
                'subjects' => $docente->subjects()->with('career')->get(),
            ]);
        } catch (Exception $e) {
            return response()->json([],500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  REMOVE SUBJECT  DELETE /api/docentes/{docente}/subjects/{subject}
    // ─────────────────────────────────────────────────────────────
    public function removeSubject(Docente $docente, int $subjectId)
    {
        try {
            $docente->subjects()->detach($subjectId);

            return response()->json([
                'message'  => 'Materia removida exitosamente.',
                'subjects' => $docente->subjects()->with('career')->get(),
            ]);
        } catch (Exception $e) {
            return response()->json([],500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  DESTROY  DELETE /api/docentes/{docente}
    // ─────────────────────────────────────────────────────────────
    public function destroy(Docente $docente)
    {
        try {
            $docente->delete();
            return response()->json(['message' => 'Docente eliminado exitosamente.']);
        } catch (Exception $e) {
            return response()->json([],500);
        }
    }
}
