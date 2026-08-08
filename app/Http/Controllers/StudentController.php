<?php

namespace App\Http\Controllers;

use App\Models\Parallel;
use App\Models\Career;
use App\Models\Course;
use App\Models\Qualification;
use App\Models\Student;
use App\Models\StudentCareer;
use App\Models\StudentParallel;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserRoles;
use Illuminate\Support\Facades\Hash;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\now;

class StudentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try{
            $perPage = $request->input('per_page', 10);
            $search  = $request->input('search');

            $query = Student::select([
                'id',
                'user_id',
                'birth_certificate',
                'user_id',
                'school_diploma',
                'carnet'
            ])->with([
                'user:id,name,first_lastname,second_lastname,email,cellphone,ci,status',
                'studentCareers.career:id,name',
            ]);

            if ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                      ->orWhere('first_lastname', 'like', "%$search%")
                      ->orWhere('second_lastname', 'like', "%$search%")
                      ->orWhere('ci', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%");
                });
            }
            $total = Student::count();

            $actives = Student::join('users', 'users.id', '=', 'students.user_id')
                ->where('users.status', 1)
                ->count();

            $inactive = Student::join('users', 'users.id', '=', 'students.user_id')
                ->where('users.status', 0)
                ->count();

            return response()->json([
                'students' => $query->paginate($perPage),
                'total'    => $total,
                'actives'  => $actives,
                'inactive' => $inactive,
            ]);
        }catch(\Exception $e){
            return response()->json([
                'message' => 'Error al obtener estudiantes--'.$e,
            ], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {

    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // User
            'name' => ['required', 'string', 'max:255'],
            'first_lastname' => ['required', 'string', 'max:255'],
            'second_lastname' => ['nullable', 'string', 'max:255'],
            'ci' => ['required', 'string', 'max:12', 'unique:users,ci'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'cellphone' => ['nullable', 'max:8'],

            // Student
            'career_id' => ['required', 'exists:careers,id'],
            'birth_certificate' => ['required'],
            'school_diploma' => ['required'],
            'carnet' => ['required'],
            'parallel_id' => ['required', 'exists:parallels,id'],
        ], [
            'ci.unique' => 'El C.I. ya está registrado',
            'email.unique' => 'El correo electrónico ya está registrado',
        ]);

        DB::beginTransaction();

        try {

            $user = User::create([
                'name' => $validated['name'],
                'first_lastname' => $validated['first_lastname'],
                'second_lastname' => $validated['second_lastname'] ?? null,
                'ci' => $validated['ci'],
                'email' => $validated['email'],
                'cellphone' => $validated['cellphone'] == 0 ? null : $validated['cellphone'],
                'password' => Hash::make($validated['ci'])
            ]);

            $student = Student::create([
                'user_id' => $user->id,
                'birth_certificate' => $validated['birth_certificate'],
                'school_diploma' => $validated['school_diploma'],
                'carnet' => $validated['carnet'],
            ]);

            StudentCareer::create([
                'student_id' => $student->id,
                'career_id' => $validated['career_id'],
                'enrolled' => now(),
                'matricula' => $user->ci,
            ]);

            UserRoles::create([
                'user_id' => $user->id,
                'role_id' => 4,
            ]);

            $parallel = Parallel::findOrFail($validated['parallel_id']);

            StudentParallel::create([
                'student_id' => $student->id,
                'parallel_id' => $parallel->id,
                'turno' => $parallel->turno,
            ]);


            //Asignarle materias
            $subjets = Subject::where('career_id',$validated['career_id'])->orderBy('level')->get();
            foreach($subjets as $s){
                StudentSubject::create([
                    'student_id' => $student->id,
                    'subject_id' => $s->id,
                    'status' => $s->level == 1 ? 'Registrado' : 'Falta'
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Estudiante registrado correctamente.'
            ], 201);

        } catch (Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Error al registrar el estudiante.',
                'error' => $e->getMessage(), // quitar en producción
            ], 500);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(Student $student)
    {
        try{
            $student->load([
                'user:id,name,first_lastname,second_lastname,email,ci,cellphone',
                'studentCareers.career:id,name',
                'parallels' => function ($query) {
                    $query->where('status', true)
                        ->with('parallel.course:id,name,level,career_id');
                },
            ]);

            $student->studentCareers->each(function ($sc) use ($student) {
                $current = $student->parallels->firstWhere('parallel.course.career_id', $sc->career_id);
                $sc->current_parallel = $current && $current->parallel ? [
                    'id'          => $current->parallel->id,
                    'paralelo'    => $current->parallel->paralelo,
                    'turno'       => $current->parallel->turno,
                    'level'       => $current->parallel->course ? $current->parallel->course->level : null,
                    'course_name' => $current->parallel->course ? $current->parallel->course->name : null,
                ] : null;
            });

            $allChanges = StudentParallel::where('student_id', $student->id)
                ->with('parallel.course:id,name,level,career_id')
                ->orderBy('created_at')
                ->get();

            $allCourses = Course::whereIn('career_id', $student->studentCareers->pluck('career_id'))
                ->orderBy('level')
                ->get(['id', 'career_id', 'name', 'level']);

            $parallelHistory = [];

            foreach ($student->studentCareers as $sc) {
                $career = $sc->career;
                if (!$career) continue;

                $courses = $allCourses->where('career_id', $career->id)->values();

                $changes = $allChanges->filter(function ($sp) use ($career) {
                    return $sp->parallel?->course?->career_id == $career->id;
                })
                ->values()
                ->map(function ($sp, $index) {
                    return [
                        'ordinal'     => $index + 1,
                        'active'      => (bool) $sp->status,
                        'paralelo'    => $sp->parallel?->paralelo,
                        'turno'       => $sp->parallel?->turno,
                        'level'       => $sp->parallel?->course?->level,
                        'course_name' => $sp->parallel?->course?->name,
                        'changed_at'  => $sp->created_at?->toDateTimeString(),
                    ];
                });

                $parallelHistory[] = [
                    'career_id'   => $career->id,
                    'career_name' => $career->name,
                    'courses'     => $courses,
                    'changes'     => $changes,
                ];
            }

            $subjectHistory = StudentSubject::where('student_id', $student->id)
                ->with('subject.career:id,name')
                ->get()
                ->sortBy(function ($ss) {
                    return sprintf(
                        '%04d-%s',
                        (int) ($ss->subject?->level ?? 0),
                        strtolower((string) $ss->subject?->name)
                    );
                })
                ->values();

            $finalGrades = Qualification::where('student_id', $student->id)
                ->where('published', true)
                ->whereNotNull('final_grade')
                ->pluck('final_grade', 'subject_id');

            $subjectHistory = $subjectHistory
                ->map(function ($ss) use ($finalGrades) {
                    return [
                        'name'        => $ss->subject?->name,
                        'sigla'       => $ss->subject?->sigla,
                        'level'       => $ss->subject?->level,
                        'status'      => $ss->status,
                        'final_grade' => $finalGrades->get($ss->subject_id),
                        'career_id'   => $ss->subject?->career_id,
                        'career_name' => $ss->subject?->career?->name,
                    ];
                });

            return response()->json([
                'student'          => $student,
                'parallel_history' => $parallelHistory,
                'subject_history'  => $subjectHistory,
            ]);

        }catch(\Exception $e){
            return response()->json([
                'message' => 'Error al obtener estudiantes',
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Student $student)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'first_lastname' => ['required', 'string', 'max:255'],
            'second_lastname' => ['nullable', 'string', 'max:255'],
            'ci' => ['required', 'string', 'max:12', 'unique:users,ci,' . $student->user_id],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $student->user_id],
            'cellphone' => ['nullable', 'max:8'],
            'birth_certificate' => ['required', 'boolean'],
            'school_diploma' => ['required', 'boolean'],
            'carnet' => ['required', 'boolean'],
        ], [
            'ci.unique' => 'El C.I. ya está registrado',
            'email.unique' => 'El correo electrónico ya está registrado',
        ]);

        DB::beginTransaction();

        try {
            $user = $student->user;
            $user->update([
                'name' => $validated['name'],
                'first_lastname' => $validated['first_lastname'],
                'second_lastname' => $validated['second_lastname'] ?? null,
                'ci' => $validated['ci'],
                'email' => $validated['email'],
                'cellphone' => $validated['cellphone'] ?? null,
            ]);

            $student->update([
                'birth_certificate' => $validated['birth_certificate'],
                'school_diploma' => $validated['school_diploma'],
                'carnet' => $validated['carnet'],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Estudiante actualizado correctamente.',
                'student' => $student->load('user'),
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al actualizar el estudiante.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    //dar de baja
    public function withdraw(Request $request, Student $student, Career $career)
    {
        try {
            $studentCareer = StudentCareer::where('student_id', $student->id)
                ->where('career_id', $career->id)
                ->firstOrFail();

            $studentCareer->update(['status' => 'Suspendido']);

            // Desactivar los paralelos activos del estudiante en esa carrera para liberar cupo
            StudentParallel::where('student_id', $student->id)
                ->whereHas('parallel.course', function ($query) use ($career) {
                    $query->where('career_id', $career->id);
                })
                ->where('status', true)
                ->update([
                    'status' => false
                ]);

            return response()->json([
                'message' => 'Baja procesada correctamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Error al procesar baja: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reinstate(Request $request, Student $student, $career)
    {
        try {
            $studentCareer = StudentCareer::where('student_id', $student->id)
                ->where('career_id', $career)
                ->firstOrFail();

            $studentCareer->update(['status' => 'Activo']);

            return response()->json([
                'message' => 'Readmisión procesada correctamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Error al procesar readmisión: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateParallel(Request $request, Student $student)
    {
        $validated = $request->validate([
            'career_id' => ['required', 'exists:careers,id'],
            'parallel_id' => ['required', 'exists:parallels,id'],
        ]);

        DB::beginTransaction();
        try {
            $exists = StudentParallel::where('student_id', $student->id)
                ->where('parallel_id',$validated['parallel_id'])
                ->where('status', true)->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'El estudiante ya se encuentra asignado a este paralelo.'
                ], 409);
            }

            $parallel = Parallel::with('course.career')->findOrFail($validated['parallel_id']);

            $careerId = $parallel->course->career->id;

            // Desactivar solo el paralelo de esa carrera
            StudentParallel::where('student_id', $student->id)
            ->whereHas('parallel.course', function ($query) use ($careerId) {
                $query->where('career_id', $careerId);
            })
            ->where('status', true)
            ->update([
                'status' => false
            ]);

            // Crear nuevo paralelo activo
            $studentParallel = StudentParallel::create([
                'student_id' => $student->id,
                'parallel_id' => $validated['parallel_id'],
                'status' => true,
            ]);
            DB::commit();
            return response()->json([
                'message' => 'Paralelo actualizado correctamente.'
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al actualizar paralelo: '
            ], 500);
        }
    }

    /**
     * Avanzar de nivel: evalúa materias del nivel actual, asigna las del siguiente
     * según pre-requisitos y mueve al estudiante al nuevo paralelo.
     */
    public function advanceLevel(Request $request, Student $student)
    {
        if (!auth()->user()->roles->contains('id', 1)) {
            return response()->json([
                'message' => 'Solo el administrador puede realizar esta acción.'
            ], 403);
        }

        $validated = $request->validate([
            'career_id' => ['required', 'exists:careers,id'],
            'parallel_id' => ['required', 'exists:parallels,id'],
        ]);

        DB::beginTransaction();
        try {
            $career = Career::findOrFail($validated['career_id']);

            // Paralelo activo actual → nivel actual
            $currentStudentParallel = StudentParallel::where('student_id', $student->id)
                ->where('status', true)
                ->whereHas('parallel.course', function ($query) use ($validated) {
                    $query->where('career_id', $validated['career_id']);
                })
                ->with('parallel.course')
                ->first();

            if (!$currentStudentParallel) {
                return response()->json([
                    'message' => 'El estudiante no tiene un paralelo activo en esta carrera.'
                ], 422);
            }

            $currentLevel = (int) $currentStudentParallel->parallel->course->level;
            $newLevel = $currentLevel + 1;

            // Validar que el paralelo destino pertenezca al curso del siguiente nivel
            $newParallel = Parallel::with('course')
                ->findOrFail($validated['parallel_id']);

            if ($newParallel->course->career_id != $validated['career_id']) {
                return response()->json([
                    'message' => 'El paralelo seleccionado no pertenece a la carrera indicada.'
                ], 422);
            }

            if ((int) $newParallel->course->level !== $newLevel) {
                return response()->json([
                    'message' => 'El paralelo seleccionado no corresponde al siguiente nivel (nivel ' . $newLevel . ').'
                ], 422);
            }

            // Validar total de niveles según tipo (1=Anual, 2=Semestral) × duración
            $totalLevels = (int) $career->type * (int) $career->duration;
            if ($newLevel > $totalLevels) {
                return response()->json([
                    'message' => 'El estudiante ya cursa el último nivel de la carrera.'
                ], 422);
            }

            // Evitar duplicar el paralelo activo destino
            $existsActive = StudentParallel::where('student_id', $student->id)
                ->where('parallel_id', $validated['parallel_id'])
                ->where('status', true)
                ->exists();

            if ($existsActive) {
                return response()->json([
                    'message' => 'El estudiante ya se encuentra asignado a este paralelo.'
                ], 409);
            }

            $careerSubjects = Subject::where('career_id', $validated['career_id'])->get();
            $careerSubjectIds = $careerSubjects->pluck('id');

            // Materias actuales del estudiante en esta carrera
            $studentSubjects = StudentSubject::where('student_id', $student->id)
                ->whereIn('subject_id', $careerSubjectIds)
                ->get()
                ->keyBy('subject_id');

            // 1) Evaluar aprobación de materias en estado 'Registrado'
            $publishedGrades = Qualification::where('student_id', $student->id)
                ->where('published', true)
                ->whereIn('subject_id', $careerSubjectIds)
                ->get()
                ->keyBy('subject_id');

            $approved = [];
            $repeated = [];

            foreach ($studentSubjects as $ss) {
                if ($ss->status !== 'Registrado') {
                    continue;
                }

                $subject = $careerSubjects->firstWhere('id', $ss->subject_id);
                $qual = $publishedGrades->get($ss->subject_id);

                $passed = $qual && $qual->final_grade !== null && $qual->final_grade >= 51;

                if ($passed) {
                    $ss->update(['status' => 'Aprobado']);
                    $approved[] = [
                        'id' => $subject->id,
                        'sigla' => $subject->sigla,
                        'name' => $subject->name,
                        'level' => $subject->level,
                    ];
                } else {
                    $repeated[] = [
                        'id' => $subject->id,
                        'sigla' => $subject->sigla,
                        'name' => $subject->name,
                        'level' => $subject->level,
                    ];
                }
            }

            // 2) Asignar materias del siguiente nivel según pre-requisitos
            $nextLevelSubjects = $careerSubjects
                ->where('level', $newLevel)
                ->sortBy('name');

            $assigned = [];
            $missingByPrerequisite = [];

            foreach ($nextLevelSubjects as $subject) {
                $prerequisiteMet = true;

                if ($subject->subject_id) {
                    $prereq = $studentSubjects->get($subject->subject_id);
                    $prerequisiteMet = $prereq && $prereq->status === 'Aprobado';
                }

                if ($prerequisiteMet) {
                    StudentSubject::updateOrCreate(
                        ['student_id' => $student->id, 'subject_id' => $subject->id],
                        ['status' => 'Registrado']
                    );
                    $studentSubjects[$subject->id] = StudentSubject::where('student_id', $student->id)
                        ->where('subject_id', $subject->id)
                        ->first();
                    $assigned[] = [
                        'id' => $subject->id,
                        'sigla' => $subject->sigla,
                        'name' => $subject->name,
                        'level' => $subject->level,
                    ];
                } else {
                    StudentSubject::updateOrCreate(
                        ['student_id' => $student->id, 'subject_id' => $subject->id],
                        ['status' => 'Falta']
                    );
                    $missingByPrerequisite[] = [
                        'id' => $subject->id,
                        'sigla' => $subject->sigla,
                        'name' => $subject->name,
                        'level' => $subject->level,
                    ];
                }
            }

            // 3) Mover al estudiante al paralelo del siguiente nivel
            StudentParallel::where('student_id', $student->id)
                ->whereHas('parallel.course', function ($query) use ($validated) {
                    $query->where('career_id', $validated['career_id']);
                })
                ->where('status', true)
                ->update(['status' => false]);

            $studentParallel = StudentParallel::create([
                'student_id' => $student->id,
                'parallel_id' => $validated['parallel_id'],
                'status' => true,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Nivel avanzado correctamente.',
                'current_level' => $currentLevel,
                'new_level' => $newLevel,
                'approved' => $approved,
                'repeated' => $repeated,
                'assigned' => $assigned,
                'missing_by_prerequisite' => $missingByPrerequisite,
                'parallel' => [
                    'id' => $studentParallel->parallel_id,
                    'paralelo' => $newParallel->paralelo,
                    'turno' => $newParallel->turno,
                    'course' => $newParallel->course->name,
                    'level' => $newParallel->course->level,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al avanzar de nivel.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function addCareer(Request $request)
    {
        $validated = $request->validate([
            'student_id'  => ['required', 'exists:students,id'],
            'career_id'   => ['required', 'exists:careers,id'],
            'parallel_id' => ['required', 'exists:parallels,id'],
        ]);

        DB::beginTransaction();
        try {
            $student = Student::findOrFail($validated['student_id']);
            $user = User::findOrFail($student->user_id);

            // Verificar si ya está registrado
            $exists = StudentCareer::where('student_id', $validated['student_id'])
                ->where('career_id', $validated['career_id'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'El estudiante ya está inscrito en esta carrera.xx'
                ], 409);
            }

            $studentCareer = StudentCareer::create([
                'student_id' => $validated['student_id'],
                'career_id'  => $validated['career_id'],
                'enrolled'   => now(),
                'matricula'  => $user->ci,
            ]);

            $parallel = Parallel::findOrFail($request->parallel_id);
            StudentParallel::create([
                'student_id' => $request->student_id,
                'parallel_id' => $request->parallel_id,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Carrera asignada correctamente.',
                'data' => $studentCareer
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Ocurrió un error al registrar la carrera.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Student $student)
    {
        //
    }
}
