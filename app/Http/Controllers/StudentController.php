<?php

namespace App\Http\Controllers;

use App\Models\Parallel;
use App\Models\Career;
use App\Models\Course;
use App\Models\Qualification;
use App\Models\Student;
use App\Models\StudentCareer;
use App\Models\StudentConvalidation;
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
    /** @var array IDs de student_subject ya procesadas en evaluateAdvance (evita duplicar) */
    private array $processedSubjectIds = [];

    /**
     * Pre-registro de estudiantes a un courses.
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
                'message' => 'Error al obtener estudiantes.',
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
            'cellphone' => ['nullable', 'numeric', 'max:99999999'],

            // Student
            'career_id' => ['required', 'exists:careers,id'],
            'birth_certificate' => ['required'],
            'school_diploma' => ['required'],
            'carnet' => ['required'],
            'parallel_id' => ['required', 'exists:parallels,id'],

            // Convalidación (opcional)
            'convalidation_type' => ['nullable', 'in:BTH,Tecnico_Medio'],
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

            $career = Career::findOrFail($validated['career_id']);

            StudentCareer::create([
                'student_id' => $student->id,
                'career_id' => $validated['career_id'],
                'enrolled' => now(),
                'matricula' => $this->generateMatricula($career, $user),
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

            // Determinar nivel de inicio según convalidación
            $startLevel = 1;
            if (!empty($validated['convalidation_type'])) {
                $startLevel = $this->calculateConvalidationStartLevel(
                    $validated['convalidation_type'],
                    $career
                );

                // Registrar convalidación
                StudentConvalidation::create([
                    'student_id' => $student->id,
                    'career_id' => $validated['career_id'],
                    'type' => $validated['convalidation_type'],
                    'start_level' => $startLevel,
                ]);
            }

            // Asignar materias DESDE el nivel de inicio (las anteriores NO se crean)
            $subjects = Subject::where('career_id', $validated['career_id'])
                ->where('level', '>=', $startLevel)
                ->orderBy('level')
                ->get();

            foreach ($subjects as $s) {
                StudentSubject::create([
                    'student_id' => $student->id,
                    'subject_id' => $s->id,
                    'status' => $s->level == $startLevel ? 'Registrado' : 'Falta'
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Estudiante registrado correctamente.',
                'convalidation' => !empty($validated['convalidation_type']) ? [
                    'type' => $validated['convalidation_type'],
                    'start_level' => $startLevel,
                ] : null,
            ], 201);

        } catch (Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Error al registrar el estudiante.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function storeFromUser(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'career_id' => ['required', 'exists:careers,id'],
            'parallel_id' => ['required', 'exists:parallels,id'],
            'birth_certificate' => ['required'],
            'school_diploma' => ['required'],
            'carnet' => ['required'],
            'convalidation_type' => ['nullable', 'in:BTH,Tecnico_Medio'],
        ]);

        $exists = Student::where('user_id', $validated['user_id'])->exists();
        if ($exists) {
            return response()->json(['message' => 'Este usuario ya tiene un registro de estudiante.'], 422);
        }

        DB::beginTransaction();

        try {
            $user = User::findOrFail($validated['user_id']);

            $student = Student::create([
                'user_id' => $user->id,
                'birth_certificate' => $validated['birth_certificate'],
                'school_diploma' => $validated['school_diploma'],
                'carnet' => $validated['carnet'],
            ]);

            $career = Career::findOrFail($validated['career_id']);

            StudentCareer::create([
                'student_id' => $student->id,
                'career_id' => $validated['career_id'],
                'enrolled' => now(),
                'matricula' => $this->generateMatricula($career, $user),
            ]);

            $hasRole = UserRoles::where('user_id', $user->id)
                ->where('role_id', 4)
                ->exists();

            if (!$hasRole) {
                UserRoles::create([
                    'user_id' => $user->id,
                    'role_id' => 4,
                ]);
            }

            $parallel = Parallel::findOrFail($validated['parallel_id']);

            StudentParallel::create([
                'student_id' => $student->id,
                'parallel_id' => $parallel->id,
                'turno' => $parallel->turno,
            ]);

            $startLevel = 1;
            if (!empty($validated['convalidation_type'])) {
                $startLevel = $this->calculateConvalidationStartLevel(
                    $validated['convalidation_type'],
                    $career
                );

                StudentConvalidation::create([
                    'student_id' => $student->id,
                    'career_id' => $validated['career_id'],
                    'type' => $validated['convalidation_type'],
                    'start_level' => $startLevel,
                ]);
            }

            $subjects = Subject::where('career_id', $validated['career_id'])
                ->where('level', '>=', $startLevel)
                ->orderBy('level')
                ->get();

            foreach ($subjects as $s) {
                StudentSubject::create([
                    'student_id' => $student->id,
                    'subject_id' => $s->id,
                    'status' => $s->level == $startLevel ? 'Registrado' : 'Falta'
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Estudiante registrado correctamente.',
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al registrar el estudiante.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calcula el nivel de inicio según tipo de convalidación y tipo de carrera.
     * BTH: Anual → nivel 2, Semestral → nivel 3
     * Técnico Medio: Anual → nivel 3, Semestral → nivel 5
     */
    private function calculateConvalidationStartLevel(string $type, Career $career): int
    {
        $isSemestral = (int) $career->type === 2;

        if ($type === 'BTH') {
            return $isSemestral ? 3 : 2;
        }

        // Tecnico_Medio
        return $isSemestral ? 5 : 3;
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
     * Export student academic history as Excel.
     */
    public function exportAcademicHistory(Student $student)
    {
        try {
            $careerId = request('career_id');
            $service = new \App\Services\StudentAcademicHistoryExportService();
            $filePath = $service->generate($student->id, $careerId);

            $fileName = basename($filePath);

            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al generar el historial académico',
                'error' => $e->getMessage(),
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
            'ci' => ['nullable', 'string', 'max:12'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $student->user_id],
            'cellphone' => ['nullable', 'numeric', 'max:99999999'],
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
            'max_subjects' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $maxSubjects = $validated['max_subjects'] ?? 6;

        DB::beginTransaction();
        try {
            $career = Career::findOrFail($validated['career_id']);

            // Evaluar y persistir en una sola llamada (evita duplicados)
            $evaluation = $this->evaluateAdvance($student, $career, true, $maxSubjects);

            if (empty($evaluation['has_active_parallel'])) {
                DB::rollBack();
                return response()->json([
                    'message' => 'El estudiante no tiene un paralelo activo en esta carrera.'
                ], 422);
            }

            if ($evaluation['is_last_level']) {
                DB::rollBack();
                return response()->json([
                    'message' => 'El estudiante ya cursa el último nivel de la carrera.'
                ], 422);
            }

            if (empty($evaluation['can_advance'])) {
                DB::rollBack();
                $reasons = $evaluation['block_reasons'] ?? [];
                $details = collect($reasons)->pluck('sigla')->implode(', ');
                return response()->json([
                    'message' => 'El estudiante no puede avanzar. Materias bloqueadas: ' . $details,
                    'can_advance' => false,
                    'block_reasons' => $reasons,
                ]);
            }

            // Validar que el paralelo destino pertenezca al curso del siguiente nivel
            $newParallel = Parallel::with('course')
                ->findOrFail($validated['parallel_id']);

            if ($newParallel->course->career_id != $validated['career_id']) {
                DB::rollBack();
                return response()->json([
                    'message' => 'El paralelo seleccionado no pertenece a la carrera indicada.'
                ], 422);
            }

            if ((int) $newParallel->course->level !== $evaluation['new_level']) {
                DB::rollBack();
                return response()->json([
                    'message' => 'El paralelo seleccionado no corresponde al siguiente nivel (nivel ' . $evaluation['new_level'] . ').'
                ], 422);
            }

            // Evitar duplicar el paralelo activo destino
            $existsActive = StudentParallel::where('student_id', $student->id)
                ->where('parallel_id', $validated['parallel_id'])
                ->where('status', true)
                ->exists();

            if ($existsActive) {
                DB::rollBack();
                return response()->json([
                    'message' => 'El estudiante ya se encuentra asignado a este paralelo.'
                ], 409);
            }

            // Validar cupo del paralelo destino
            $destStudentsCount = StudentParallel::where('parallel_id', $validated['parallel_id'])
                ->where('status', true)
                ->count();

            $available = (int) $newParallel->limit - $destStudentsCount;

            if ($available <= 0) {
                DB::rollBack();
                return response()->json([
                    'message' => 'El paralelo seleccionado no tiene cupo disponible.'
                ], 422);
            }

            // Mover al estudiante al paralelo del siguiente nivel
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
                'current_level' => $evaluation['current_level'],
                'new_level' => $evaluation['new_level'],
                'approved' => $evaluation['approved'],
                'repeated' => $evaluation['repeated'],
                'pending' => $evaluation['pending'],
                'assigned' => $evaluation['assigned'],
                'missing_by_prerequisite' => $evaluation['missing_by_prerequisite'],
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

    /**
     * Procesa el avance automático de todos los estudiantes de un paralelo.
     * - Si TODAS las materias del nivel actual están aprobadas (>=61), avanza al siguiente nivel.
     * - Si NO puede avanzar, re-asigna solo las materias cuyo pre-requisito se cumplió
     *   y marca como "Reprobado" las materias bloqueadas (para historial).
     * Retorna el resumen de lo procesado.
     */
    public function processAutoAdvance(Parallel $parallel): array
    {
        $career = $parallel->course->career;

        $activeStudents = StudentParallel::where('parallel_id', $parallel->id)
            ->where('status', true)
            ->with('student')
            ->get();

        $results = [
            'advanced' => 0,
            're_registered' => 0,
            'blocked' => 0,
            'details' => [],
        ];

        foreach ($activeStudents as $sp) {
            $student = $sp->student;
            if (!$student) continue;

            $preview = $this->evaluateAdvance($student, $career, false);

            if (!$preview['has_active_parallel']) continue;

            if ($preview['is_last_level']) {
                $results['details'][] = [
                    'student_id' => $student->id,
                    'name' => trim($student->user->name . ' ' . $student->user->first_lastname . ' ' . $student->user->second_lastname),
                    'action' => 'last_level',
                ];
                continue;
            }

            if ($preview['can_advance']) {
                // TODAS las materias aprobadas → avanzar automáticamente
                $evaluation = $this->evaluateAdvance($student, $career, true);

                // Buscar paralelo del siguiente nivel con cupo
                $nextCourse = Course::where('career_id', $career->id)
                    ->where('level', $preview['new_level'])
                    ->first();

                if ($nextCourse) {
                    $nextParallel = Parallel::where('course_id', $nextCourse->id)
                        ->where('status', 1)
                        ->withCount(['students as students_count' => function ($q) {
                            $q->where('status', true);
                        }])
                        ->get()
                        ->first(function ($p) {
                            return $p->students_count < $p->limit;
                        });

                    if ($nextParallel) {
                        // Desactivar paralelo actual
                        StudentParallel::where('student_id', $student->id)
                            ->where('parallel_id', $parallel->id)
                            ->where('status', true)
                            ->update(['status' => false]);

                        // Asignar nuevo paralelo
                        StudentParallel::create([
                            'student_id' => $student->id,
                            'parallel_id' => $nextParallel->id,
                            'status' => true,
                        ]);

                        $results['advanced']++;
                        $results['details'][] = [
                            'student_id' => $student->id,
                            'name' => trim($student->user->name . ' ' . $student->user->first_lastname . ' ' . $student->user->second_lastname),
                            'action' => 'advanced',
                            'from_level' => $preview['current_level'],
                            'to_level' => $preview['new_level'],
                            'parallel' => $nextParallel->paralelo,
                        ];
                    } else {
                        $results['blocked']++;
                        $results['details'][] = [
                            'student_id' => $student->id,
                            'name' => trim($student->user->name . ' ' . $student->user->first_lastname . ' ' . $student->user->second_lastname),
                            'action' => 'no_capacity',
                        ];
                    }
                }
            } else {
                // NO puede avanzar → re-asignar solo materias con prerequisito cumplido
                $this->reRegisterStudentSubjects($student, $career, $preview);

                $results['re_registered']++;
                $results['details'][] = [
                    'student_id' => $student->id,
                    'name' => trim($student->user->name . ' ' . $student->user->first_lastname . ' ' . $student->user->second_lastname),
                    'action' => 're_registered',
                    'reasons' => count($preview['block_reasons']),
                ];
            }
        }

        return $results;
    }

    /**
     * Re-asigna materias de un estudiante que NO puede avanzar.
     * - Materias con prerequisito cumplido → se asignan como 'Registrado' (para llevar)
     * - Materias bloqueadas → se marcan como 'Reprobado' con nota (para historial)
     */
    private function reRegisterStudentSubjects(Student $student, Career $career, array $preview): void
    {
        $currentLevel = $preview['current_level'];
        $careerSubjects = Subject::where('career_id', $career->id)->get();
        $careerSubjectIds = $careerSubjects->pluck('id');

        $studentSubjects = StudentSubject::where('student_id', $student->id)
            ->whereIn('subject_id', $careerSubjectIds)
            ->get()
            ->keyBy('subject_id');

        $publishedGrades = Qualification::where('student_id', $student->id)
            ->where('published', true)
            ->whereIn('subject_id', $careerSubjectIds)
            ->get()
            ->keyBy('subject_id');

        $currentLevelSubjects = $careerSubjects->where('level', $currentLevel);

        foreach ($currentLevelSubjects as $cs) {
            $ss = $studentSubjects->get($cs->id);
            $qual = $publishedGrades->get($cs->id);

            if (!$ss) continue;

            $hasGrade = $qual && $qual->final_grade !== null;
            $passed = $hasGrade && $qual->final_grade >= 61;
            $passedWithRecovery = $qual && !$passed && $qual->recovery_grade !== null && $qual->recovery_grade >= 51;

            if ($passed || $passedWithRecovery) {
                // Aprobada →保持 como 'Aprobado'
                if ($ss->status !== 'Aprobado') {
                    $ss->update(['status' => 'Aprobado']);
                }
            } else {
                // Reprobada o sin calificación → marcar como 'Reprobado' para historial
                if ($ss->status !== 'Reprobado') {
                    $ss->update(['status' => 'Reprobado']);
                }
            }
        }

        // Asignar materias del siguiente nivel cuyo prerequisito se cumplió
        if (!$preview['is_last_level']) {
            $newLevel = $preview['new_level'];
            $nextLevelSubjects = $careerSubjects->where('level', $newLevel)->sortBy('name');

            foreach ($nextLevelSubjects as $subject) {
                $prerequisiteMet = true;

                if ($subject->subject_id) {
                    $prereq = $studentSubjects->get($subject->subject_id);
                    $prereqQual = $publishedGrades->get($subject->subject_id);

                    if (!$prereq) {
                        $prerequisiteMet = false;
                    } else {
                        $finalGrade = $prereqQual?->final_grade;
                        $recoveryGrade = $prereqQual?->recovery_grade;
                        $passed = $finalGrade !== null && $finalGrade >= 61;
                        $passedWithRecovery = $recoveryGrade !== null && $recoveryGrade >= 51 && !$passed;
                        $prerequisiteMet = $passed || $passedWithRecovery;
                    }
                }

                $existing = $studentSubjects->get($subject->id);

                if ($prerequisiteMet) {
                    // Prerequisito cumplido → asignar como 'Registrado'
                    if (!$existing || $existing->status !== 'Registrado') {
                        $rec = StudentSubject::updateOrCreate(
                            ['student_id' => $student->id, 'subject_id' => $subject->id],
                            ['status' => 'Registrado']
                        );
                        $this->processedSubjectIds[] = $rec->id;
                    }
                } else {
                    // Prerequisito NO cumplido → asignar como 'Falta'
                    if (!$existing || ($existing->status !== 'Falta' && $existing->status !== 'Reprobado')) {
                        $rec = StudentSubject::updateOrCreate(
                            ['student_id' => $student->id, 'subject_id' => $subject->id],
                            ['status' => 'Falta']
                        );
                    }
                }
            }
        }
    }

    /**
     * Vista previa (solo lectura) del avance de nivel: evalúa las materias
     * sin persistir ningún cambio.
     */
    public function previewAdvanceLevel(Request $request, Student $student)
    {
        if (!auth()->user()->roles->contains('id', 1)) {
            return response()->json([
                'message' => 'Solo el administrador puede realizar esta acción.'
            ], 403);
        }

        $validated = $request->validate([
            'career_id' => ['required', 'exists:careers,id'],
            'max_subjects' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $maxSubjects = $validated['max_subjects'] ?? 6;

        try {
            $career = Career::findOrFail($validated['career_id']);

            $preview = $this->evaluateAdvance($student, $career, false, $maxSubjects);

            if (empty($preview['has_active_parallel'])) {
                return response()->json([
                    'message' => 'El estudiante no tiene un paralelo activo en esta carrera.'
                ], 422);
            }

            // Paralelos disponibles del siguiente nivel
            $availableParallels = [];

            if (!$preview['is_last_level']) {
                $course = Course::where('career_id', $career->id)
                    ->where('level', $preview['new_level'])
                    ->first();

                if ($course) {
                    $availableParallels = Parallel::where('course_id', $course->id)
                        ->where('status', 1)
                        ->with('course')
                        ->withCount([
                            'students as students_count' => function ($query) {
                                $query->where('status', true);
                            }
                        ])->get()->map(function ($parallel) {
                            $parallel->available = $parallel->limit - $parallel->students_count;
                            $parallel->course_name = $parallel->course->name ?? null;
                            return $parallel;
                        })->filter(function ($parallel) {
                            return $parallel->available > 0;
                        })->values();
                }
            }

            return response()->json([
                'message' => 'Vista previa del avance de nivel.',
                'current_level' => $preview['current_level'],
                'new_level' => $preview['new_level'],
                'total_levels' => $preview['total_levels'],
                'is_last_level' => $preview['is_last_level'],
                'can_advance' => $preview['can_advance'],
                'block_reasons' => $preview['block_reasons'],
                'approved' => $preview['approved'],
                'repeated' => $preview['repeated'],
                'assigned' => $preview['assigned'],
                'missing_by_prerequisite' => $preview['missing_by_prerequisite'],
                'available_parallels' => $availableParallels,
                'current_parallel' => $preview['current_parallel'],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al obtener la vista previa.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Egresar a un estudiante que cursa el último nivel de una carrera.
     */
    public function graduate(Request $request, Student $student)
    {
        if (!auth()->user()->roles->contains('id', 1)) {
            return response()->json([
                'message' => 'Solo el administrador puede realizar esta acción.'
            ], 403);
        }

        $validated = $request->validate([
            'career_id' => ['required', 'exists:careers,id'],
        ]);

        DB::beginTransaction();
        try {
            $career = Career::findOrFail($validated['career_id']);

            $currentStudentParallel = StudentParallel::where('student_id', $student->id)
                ->where('status', true)
                ->whereHas('parallel.course', function ($query) use ($career) {
                    $query->where('career_id', $career->id);
                })
                ->with('parallel.course')
                ->first();

            if (!$currentStudentParallel) {
                return response()->json([
                    'message' => 'El estudiante no tiene un paralelo activo en esta carrera.'
                ], 422);
            }

            $currentLevel = (int) $currentStudentParallel->parallel->course->level;
            $totalLevels = (int) $career->type * (int) $career->duration;

            if ($currentLevel < $totalLevels) {
                return response()->json([
                    'message' => 'El estudiante aún no cursa el último nivel de la carrera.'
                ], 422);
            }

            $studentCareer = StudentCareer::where('student_id', $student->id)
                ->where('career_id', $career->id)
                ->firstOrFail();

            $studentCareer->update(['status' => 'Egresado']);

            // Liberar cupo: desactivar paralelos activos de esa carrera
            StudentParallel::where('student_id', $student->id)
                ->whereHas('parallel.course', function ($query) use ($career) {
                    $query->where('career_id', $career->id);
                })
                ->where('status', true)
                ->update(['status' => false]);

            DB::commit();

            return response()->json([
                'message' => 'Estudiante egresado correctamente.',
                'status' => 'Egresado',
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al egresar al estudiante.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Vista previa masiva (solo lectura) del avance de nivel de todos los
     * estudiantes activos de un paralelo.
     */
    public function previewParallelAdvance(Request $request, Parallel $parallel)
    {
        if (!auth()->user()->roles->contains('id', 1)) {
            return response()->json([
                'message' => 'Solo el administrador puede realizar esta acción.'
            ], 403);
        }

        $maxSubjects = (int) ($request->query('max_subjects', 6));

        try {
            $course = Course::with('career')->findOrFail($parallel->course_id);
            $career = $course->career;

            $currentLevel = (int) $course->level;
            $newLevel = $currentLevel + 1;
            $totalLevels = (int) $career->type * (int) $career->duration;
            $isLastLevelCourse = $currentLevel >= $totalLevels;

            $assignments = StudentParallel::where('parallel_id', $parallel->id)
                ->where('status', true)
                ->with('student.user')
                ->get();

            $students = [];
            $summary = [
                'total' => $assignments->count(),
                'last_level' => 0,
                'no_active_parallel' => 0,
                'advanceable' => 0,
                'blocked' => 0,
                'with_alerts' => 0,
            ];

            foreach ($assignments as $assignment) {
                $student = $assignment->student;

                $preview = $this->evaluateAdvance($student, $career, false, $maxSubjects);

                if (empty($preview['has_active_parallel'])) {
                    $summary['no_active_parallel']++;
                    $students[] = [
                        'id' => $student->id,
                        'name' => trim(($student->user->name ?? '') . ' ' . ($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '')),
                        'ci' => $student->user->ci ?? '—',
                        'is_last_level' => true,
                        'no_active_parallel' => true,
                        'current_level' => null,
                        'new_level' => null,
                        'approved' => [],
                        'repeated' => [],
                        'assigned' => [],
                        'missing_by_prerequisite' => [],
                        'prerequisite_alerts' => [],
                    ];
                    continue;
                }

                $isLastLevel = $preview['is_last_level'];

                if ($isLastLevel) {
                    $summary['last_level']++;
                } elseif (empty($preview['can_advance'])) {
                    $summary['blocked']++;
                } else {
                    $summary['advanceable']++;
                }

                $careerSubjectIds = Subject::where('career_id', $career->id)->pluck('id');
                $publishedGradesForStudent = Qualification::where('student_id', $student->id)
                    ->where('published', true)
                    ->whereIn('subject_id', $careerSubjectIds)
                    ->get()
                    ->keyBy('subject_id');

                $prerequisiteAlerts = $this->prerequisiteAlerts($preview['missing_by_prerequisite'] ?? [], $publishedGradesForStudent);

                if (count($prerequisiteAlerts) > 0) {
                    $summary['with_alerts']++;
                }

                $students[] = [
                    'id' => $student->id,
                    'name' => trim(($student->user->name ?? '') . ' ' . ($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '')),
                    'ci' => $student->user->ci ?? '—',
                    'is_last_level' => $isLastLevel,
                    'no_active_parallel' => false,
                    'can_advance' => $preview['can_advance'] ?? true,
                    'block_reasons' => $preview['block_reasons'] ?? [],
                    'current_level' => $preview['current_level'] ?? $currentLevel,
                    'new_level' => $preview['new_level'] ?? ($currentLevel + 1),
                    'approved' => $preview['approved'] ?? [],
                    'repeated' => $preview['repeated'] ?? [],
                    'pending' => $preview['pending'] ?? [],
                    'assigned' => $preview['assigned'] ?? [],
                    'missing_by_prerequisite' => $preview['missing_by_prerequisite'] ?? [],
                    'prerequisite_alerts' => $prerequisiteAlerts,
                ];
            }

            // Paralelos disponibles del siguiente nivel
            $availableParallels = [];

            if (!$isLastLevelCourse) {
                $nextCourse = Course::where('career_id', $career->id)
                    ->where('level', $newLevel)
                    ->first();

                if ($nextCourse) {
                    $availableParallels = Parallel::where('course_id', $nextCourse->id)
                        ->where('status', 1)
                        ->with('course')
                        ->withCount([
                            'students as students_count' => function ($query) {
                                $query->where('status', true);
                            }
                        ])->get()->map(function ($p) use ($summary) {
                            $p->available = $p->limit - $p->students_count;
                            $p->required = $summary['advanceable'] ?? 0;
                            $p->sufficient = $p->available >= ($summary['advanceable'] ?? 0);
                            $p->course_name = $p->course->name ?? null;
                            return $p;
                        })->filter(function ($p) {
                            return $p->available > 0;
                        })->values();
                }
            }

            return response()->json([
                'message' => 'Vista previa del avance de nivel del paralelo.',
                'current_level' => $currentLevel,
                'new_level' => $newLevel,
                'total_levels' => $totalLevels,
                'is_last_level' => $isLastLevelCourse,
                'parallel' => [
                    'id' => $parallel->id,
                    'paralelo' => $parallel->paralelo,
                    'turno' => $parallel->turno,
                    'course' => $course->name,
                ],
                'summary' => $summary,
                'students' => $students,
                'available_parallels' => $availableParallels,
                'max_subjects' => $maxSubjects,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al obtener la vista previa del paralelo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Avanza de nivel a todos los estudiantes activos de un paralelo hacia
     * un paralelo del siguiente nivel.
     */
    public function advanceParallelLevel(Request $request, Parallel $parallel)
    {
        if (!auth()->user()->roles->contains('id', 1)) {
            return response()->json([
                'message' => 'Solo el administrador puede realizar esta acción.'
            ], 403);
        }

        $validated = $request->validate([
            'parallel_id' => ['required', 'exists:parallels,id'],
            'max_subjects' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $maxSubjects = $validated['max_subjects'] ?? 6;

        DB::beginTransaction();
        try {
            $course = Course::with('career')->findOrFail($parallel->course_id);
            $career = $course->career;

            $currentLevel = (int) $course->level;
            $newLevel = $currentLevel + 1;
            $totalLevels = (int) $career->type * (int) $career->duration;

            if ($currentLevel >= $totalLevels) {
                return response()->json([
                    'message' => 'El paralelo corresponde al último nivel de la carrera.'
                ], 422);
            }

            // Validar paralelo destino
            $newParallel = Parallel::with('course')->findOrFail($validated['parallel_id']);

            if ($newParallel->course->career_id != $career->id) {
                return response()->json([
                    'message' => 'El paralelo seleccionado no pertenece a la carrera indicada.'
                ], 422);
            }

            if ((int) $newParallel->course->level !== $newLevel) {
                return response()->json([
                    'message' => 'El paralelo seleccionado no corresponde al siguiente nivel (nivel ' . $newLevel . ').'
                ], 422);
            }

            $assignments = StudentParallel::where('parallel_id', $parallel->id)
                ->where('status', true)
                ->with('student.user')
                ->get();

            // Contar cuántos estudiantes avanzarán realmente (con paralelo activo, sin ser último nivel, y que puedan avanzar)
            $advanceableCount = 0;
            $blockedStudents = [];

            foreach ($assignments as $assignment) {
                $advancePreview = $this->evaluateAdvance($assignment->student, $career);

                if (empty($advancePreview['has_active_parallel']) || $advancePreview['is_last_level']) {
                    continue;
                }

                if (empty($advancePreview['can_advance'])) {
                    $blockedStudents[] = [
                        'student' => $assignment->student,
                        'block_reasons' => $advancePreview['block_reasons'] ?? [],
                    ];
                    continue;
                }

                $advanceableCount++;
            }

            // Validar cupo del paralelo destino
            $destStudentsCount = StudentParallel::where('parallel_id', $validated['parallel_id'])
                ->where('status', true)
                ->count();

            $available = (int) $newParallel->limit - $destStudentsCount;

            if ($advanceableCount > $available) {
                return response()->json([
                    'message' => 'El paralelo seleccionado no tiene cupo suficiente. Se requieren ' . $advanceableCount . ' cupo(s) y solo hay ' . $available . ' disponible(s).'
                ], 422);
            }

            $students = [];
            $skippedLastLevel = [];
            $skippedBlocked = [];
            $summary = [
                'total' => $assignments->count(),
                'advanced' => 0,
                'skipped_last_level' => 0,
                'skipped_no_active' => 0,
                'skipped_blocked' => 0,
                'with_alerts' => 0,
            ];

            foreach ($assignments as $assignment) {
                $student = $assignment->student;

                // Evaluar y persistir en una sola llamada (evita duplicados)
                $evaluation = $this->evaluateAdvance($student, $career, true, $maxSubjects);

                if (empty($evaluation['has_active_parallel'])) {
                    $summary['skipped_no_active']++;
                    continue;
                }

                if ($evaluation['is_last_level']) {
                    $summary['skipped_last_level']++;
                    $skippedLastLevel[] = [
                        'id' => $student->id,
                        'name' => trim(($student->user->name ?? '') . ' ' . ($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '')),
                    ];
                    continue;
                }

                if (empty($evaluation['can_advance'])) {
                    $summary['skipped_blocked']++;
                    $skippedBlocked[] = [
                        'id' => $student->id,
                        'name' => trim(($student->user->name ?? '') . ' ' . ($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '')),
                        'block_reasons' => $evaluation['block_reasons'] ?? [],
                    ];
                    continue;
                }

                $careerSubjectIdsAdv = Subject::where('career_id', $career->id)->pluck('id');
                $publishedGradesForStudent = Qualification::where('student_id', $student->id)
                    ->where('published', true)
                    ->whereIn('subject_id', $careerSubjectIdsAdv)
                    ->get()
                    ->keyBy('subject_id');

                $prerequisiteAlerts = $this->prerequisiteAlerts($evaluation['missing_by_prerequisite'] ?? [], $publishedGradesForStudent);

                if (count($prerequisiteAlerts) > 0) {
                    $summary['with_alerts']++;
                }

                // Mover al estudiante al paralelo destino (evitar duplicado activo)
                $alreadyAtDestination = StudentParallel::where('student_id', $student->id)
                    ->where('parallel_id', $validated['parallel_id'])
                    ->where('status', true)
                    ->exists();

                if (!$alreadyAtDestination) {
                    StudentParallel::where('student_id', $student->id)
                        ->whereHas('parallel.course', function ($query) use ($career) {
                            $query->where('career_id', $career->id);
                        })
                        ->where('status', true)
                        ->update(['status' => false]);

                    StudentParallel::create([
                        'student_id' => $student->id,
                        'parallel_id' => $validated['parallel_id'],
                        'status' => true,
                    ]);
                }

                $summary['advanced']++;

                $students[] = [
                    'id' => $student->id,
                    'name' => trim(($student->user->name ?? '') . ' ' . ($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '')),
                    'ci' => $student->user->ci ?? '—',
                    'current_level' => $evaluation['current_level'],
                    'new_level' => $evaluation['new_level'],
                    'approved' => $evaluation['approved'],
                    'repeated' => $evaluation['repeated'],
                    'pending' => $evaluation['pending'],
                    'assigned' => $evaluation['assigned'],
                    'missing_by_prerequisite' => $evaluation['missing_by_prerequisite'],
                    'prerequisite_alerts' => $prerequisiteAlerts,
                ];
            }

            DB::commit();

            return response()->json([
                'message' => 'Paralelo avanzado de nivel correctamente.',
                'current_level' => $currentLevel,
                'new_level' => $newLevel,
                'total_levels' => $totalLevels,
                'summary' => $summary,
                'skipped_last_level' => $skippedLastLevel,
                'skipped_blocked' => $skippedBlocked,
                'students' => $students,
                'parallel' => [
                    'id' => $newParallel->id,
                    'paralelo' => $newParallel->paralelo,
                    'turno' => $newParallel->turno,
                    'course' => $newParallel->course->name,
                    'level' => $newParallel->course->level,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al avanzar de nivel el paralelo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Avance automático de nivel para todos los estudiantes de un paralelo.
     * Se ejecuta después de publicar notas.
     * - Aprobados → avanzan automáticamente al siguiente nivel.
     * - Bloqueados → se re-asignan solo materias con prerequisito cumplido.
     */
    public function autoAdvanceParallel(Request $request, Parallel $parallel)
    {
        if (!auth()->user()->roles->contains('id', 1)) {
            return response()->json([
                'message' => 'Solo el administrador puede realizar esta acción.'
            ], 403);
        }

        try {
            $results = $this->processAutoAdvance($parallel);

            return response()->json([
                'message' => 'Proceso de avance automático completado.',
                'summary' => [
                    'advanced' => $results['advanced'],
                    're_registered' => $results['re_registered'],
                    'blocked' => $results['blocked'],
                ],
                'details' => $results['details'],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al procesar avance automático.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Construye las alertas de pre-requisito: materia adeudada → materia que
     * no puede llevar. Incluye la nota de la materia pre-requisito.
     */
    private function prerequisiteAlerts(array $missingByPrerequisite, $publishedGrades): array
    {
        $alerts = [];

        foreach ($missingByPrerequisite as $missing) {
            $blocked = Subject::find($missing['id']);
            $prereq = $blocked && $blocked->subject_id ? Subject::find($blocked->subject_id) : null;

            if ($prereq) {
                $qual = $publishedGrades->get($prereq->id);
                $finalGrade = $qual?->final_grade;
                $recoveryGrade = $qual?->recovery_grade;

                $passed = $finalGrade !== null && $finalGrade >= 61;
                $passedWithRecovery = $recoveryGrade !== null && $recoveryGrade >= 51 && !$passed;

                if ($passed) {
                    $observation = 'Aprobado';
                } elseif ($passedWithRecovery) {
                    $observation = 'Aprobado con recuperación';
                } elseif ($finalGrade !== null) {
                    $observation = 'Reprobado';
                } else {
                    $observation = 'Sin calificación';
                }

                $alerts[] = [
                    'prerequisite' => [
                        'id' => $prereq->id,
                        'sigla' => $prereq->sigla,
                        'name' => $prereq->name,
                        'final_grade' => $finalGrade,
                        'recovery_grade' => $recoveryGrade,
                        'observation' => $observation,
                    ],
                    'blocked' => [
                        'id' => $blocked->id,
                        'sigla' => $blocked->sigla,
                        'name' => $blocked->name,
                        'level' => $blocked->level,
                    ],
                ];
            }
        }

        return $alerts;
    }

    /**
     * Evalúa el avance de nivel de un estudiante en una carrera. Con $commit en true
     * persiste los cambios de estado de materias (Aprobado/Reprobado/Registrado/Falta).
     */
    private function evaluateAdvance(Student $student, Career $career, bool $commit = false, int $maxSubjects = 6): array
    {
        $this->processedSubjectIds = [];

        $currentStudentParallel = StudentParallel::where('student_id', $student->id)
            ->where('status', true)
            ->whereHas('parallel.course', function ($query) use ($career) {
                $query->where('career_id', $career->id);
            })
            ->with('parallel.course')
            ->first();

        if (!$currentStudentParallel) {
            return ['has_active_parallel' => false];
        }

        $currentLevel = (int) $currentStudentParallel->parallel->course->level;
        $newLevel = $currentLevel + 1;

        // Total de niveles según tipo (1=Anual, 2=Semestral) × duración
        $totalLevels = (int) $career->type * (int) $career->duration;
        $isLastLevel = $newLevel > $totalLevels;

        $careerSubjects = Subject::where('career_id', $career->id)->get();
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
        $pending = [];

        foreach ($studentSubjects as $ss) {
            if ($ss->status !== 'Registrado') {
                continue;
            }

            // Evitar procesar registros ya evaluados en esta transacción
            if (in_array($ss->id, $this->processedSubjectIds)) {
                continue;
            }

            $subject = $careerSubjects->firstWhere('id', $ss->subject_id);
            $qual = $publishedGrades->get($ss->subject_id);

            // Marcar como procesada para evitar duplicados en segunda llamada
            $this->processedSubjectIds[] = $ss->id;

            $hasGrade = $qual && $qual->final_grade !== null;
            $passed = $hasGrade && $qual->final_grade >= 61;
            $passedWithRecovery = $qual && !$passed && $qual->recovery_grade !== null && $qual->recovery_grade >= 51;
            $isPassed = $passed || $passedWithRecovery;

            if ($isPassed) {
                if ($commit) {
                    $ss->update(['status' => 'Aprobado']);
                }
                $approved[] = [
                    'id' => $subject->id,
                    'sigla' => $subject->sigla,
                    'name' => $subject->name,
                    'level' => $subject->level,
                    'observation' => $passed ? 'Aprobado' : 'Aprobado con recuperación',
                    'final_grade' => $qual?->final_grade,
                    'recovery_grade' => $qual?->recovery_grade,
                ];
            } elseif (!$hasGrade) {
                // Sin calificación publicada — pendiente, NO es "repite"
                $pending[] = [
                    'id' => $subject->id,
                    'sigla' => $subject->sigla,
                    'name' => $subject->name,
                    'level' => $subject->level,
                    'observation' => 'Sin calificación',
                    'final_grade' => null,
                    'recovery_grade' => null,
                ];
            } else {
                // Tiene nota pero reprobó → cambiar a 'Reprobado' y crear nuevo registro para reinscripción
                if ($commit) {
                    $ss->update(['status' => 'Reprobado']);
                    $newRecord = StudentSubject::create([
                        'student_id' => $student->id,
                        'subject_id' => $subject->id,
                        'status' => 'Registrado',
                    ]);
                    $this->processedSubjectIds[] = $newRecord->id;
                }
                $repeated[] = [
                    'id' => $subject->id,
                    'sigla' => $subject->sigla,
                    'name' => $subject->name,
                    'level' => $subject->level,
                    'observation' => 'Reprobado',
                    'final_grade' => $qual->final_grade,
                    'recovery_grade' => $qual->recovery_grade,
                ];
            }
        }

        $assigned = [];
        $missingByPrerequisite = [];

        // 2) Asignar materias del siguiente nivel según pre-requisitos (máximo $maxSubjects)
        $assignedCount = 0;
        if (!$isLastLevel) {
            $nextLevelSubjects = $careerSubjects
                ->where('level', $newLevel)
                ->sortBy('name');

            foreach ($nextLevelSubjects as $subject) {
                if ($assignedCount >= $maxSubjects) {
                    break;
                }

                $prerequisiteMet = true;

                if ($subject->subject_id) {
                    $prereq = $studentSubjects->get($subject->subject_id);
                    $prereqQual = $publishedGrades->get($subject->subject_id);

                    if (!$prereq) {
                        $prerequisiteMet = false;
                    } else {
                        $finalGrade = $prereqQual?->final_grade;
                        $recoveryGrade = $prereqQual?->recovery_grade;
                        $passed = $finalGrade !== null && $finalGrade >= 61;
                        $passedWithRecovery = $recoveryGrade !== null && $recoveryGrade >= 51 && !$passed;
                        $prerequisiteMet = $passed || $passedWithRecovery;
                    }
                }

                if ($prerequisiteMet) {
                    if ($commit) {
                        $assignedRecord = StudentSubject::updateOrCreate(
                            ['student_id' => $student->id, 'subject_id' => $subject->id],
                            ['status' => 'Registrado']
                        );
                        $this->processedSubjectIds[] = $assignedRecord->id;
                        $studentSubjects[$subject->id] = StudentSubject::where('student_id', $student->id)
                            ->where('subject_id', $subject->id)
                            ->first();
                    }
                    $assigned[] = [
                        'id' => $subject->id,
                        'sigla' => $subject->sigla,
                        'name' => $subject->name,
                        'level' => $subject->level,
                    ];
                    $assignedCount++;
                } else {
                    if ($commit) {
                        StudentSubject::updateOrCreate(
                            ['student_id' => $student->id, 'subject_id' => $subject->id],
                            ['status' => 'Falta']
                        );
                    }
                    $missingByPrerequisite[] = [
                        'id' => $subject->id,
                        'sigla' => $subject->sigla,
                        'name' => $subject->name,
                        'level' => $subject->level,
                    ];
                }
            }
        }

        // 3) Determinar si el estudiante puede avanzar
        // Puede avanzar si puede llevar al menos 1 materia del siguiente nivel
        $currentLevelSubjects = $careerSubjects->where('level', $currentLevel);
        $canAdvance = count($assigned) > 0;
        $blockReasons = [];

        foreach ($currentLevelSubjects as $cs) {
            $ss = $studentSubjects->get($cs->id);
            if (!$ss) {
                $canAdvance = false;
                $blockReasons[] = [
                    'id' => $cs->id,
                    'sigla' => $cs->sigla,
                    'name' => $cs->name,
                    'level' => $cs->level,
                    'status' => 'No inscrito',
                    'reason' => 'La materia no está inscrita',
                    'final_grade' => null,
                    'recovery_grade' => null,
                    'observation' => 'No inscrito',
                ];
            } elseif ($ss->status === 'Reprobado') {
                $qual = $publishedGrades->get($cs->id);
                $finalGrade = $qual?->final_grade;
                $recoveryGrade = $qual?->recovery_grade;
                $passed = $finalGrade !== null && $finalGrade >= 61;
                $passedWithRecovery = $recoveryGrade !== null && $recoveryGrade >= 51 && !$passed;
                $effectiveGrade = $passedWithRecovery ? $recoveryGrade : $finalGrade;
                $observation = $passed ? 'Aprobado' : ($passedWithRecovery ? 'Aprobado con recuperación' : 'Reprobado');

                if ($passedWithRecovery) {
                    $approved[] = [
                        'id' => $cs->id,
                        'sigla' => $cs->sigla,
                        'name' => $cs->name,
                        'level' => $cs->level,
                    ];
                } else {
                    $canAdvance = false;
                    $blockReasons[] = [
                        'id' => $cs->id,
                        'sigla' => $cs->sigla,
                        'name' => $cs->name,
                        'level' => $cs->level,
                        'status' => 'Reprobado',
                        'reason' => 'La materia tiene nota menor a 61',
                        'final_grade' => $finalGrade,
                        'recovery_grade' => $recoveryGrade,
                        'observation' => $observation,
                    ];
                }
            } elseif ($ss->status === 'Registrado') {
                $qual = $publishedGrades->get($cs->id);
                if (!$qual || $qual->final_grade === null) {
                    $canAdvance = false;
                    $blockReasons[] = [
                        'id' => $cs->id,
                        'sigla' => $cs->sigla,
                        'name' => $cs->name,
                        'level' => $cs->level,
                        'status' => 'Sin calificación',
                        'reason' => 'La materia no tiene calificación publicada',
                        'final_grade' => null,
                        'recovery_grade' => null,
                        'observation' => 'Sin calificación',
                    ];
                } elseif ($qual->final_grade < 61) {
                    $recoveryGrade = $qual->recovery_grade;
                    $passed = false;
                    $passedWithRecovery = $recoveryGrade !== null && $recoveryGrade >= 51;
                    $effectiveGrade = $passedWithRecovery ? $recoveryGrade : $qual->final_grade;
                    $observation = $passedWithRecovery ? 'Aprobado con recuperación' : 'Reprobado';

                    if ($passedWithRecovery) {
                        $approved[] = [
                            'id' => $cs->id,
                            'sigla' => $cs->sigla,
                            'name' => $cs->name,
                            'level' => $cs->level,
                        ];
                    } else {
                        $canAdvance = false;
                        $blockReasons[] = [
                            'id' => $cs->id,
                            'sigla' => $cs->sigla,
                            'name' => $cs->name,
                            'level' => $cs->level,
                            'status' => 'Reprobado',
                            'reason' => 'La materia tiene nota ' . $qual->final_grade . ' (menor a 61)',
                            'final_grade' => $qual->final_grade,
                            'recovery_grade' => $recoveryGrade,
                            'observation' => $observation,
                        ];
                    }
                } else {
                    $recoveryGrade = $qual->recovery_grade;
                    $passedWithRecovery = $recoveryGrade !== null && $recoveryGrade >= 51;
                    $observation = $passedWithRecovery ? 'Aprobado con recuperación' : 'Aprobado';
                    $approved[] = [
                        'id' => $cs->id,
                        'sigla' => $cs->sigla,
                        'name' => $cs->name,
                        'level' => $cs->level,
                    ];
                }
            }
        }

        return [
            'has_active_parallel' => true,
            'current_level' => $currentLevel,
            'new_level' => $newLevel,
            'total_levels' => $totalLevels,
            'is_last_level' => $isLastLevel,
            'can_advance' => $canAdvance,
            'block_reasons' => $blockReasons,
            'approved' => $approved,
            'repeated' => $repeated,
            'pending' => $pending,
            'assigned' => $assigned,
            'missing_by_prerequisite' => $missingByPrerequisite,
            'max_subjects' => $maxSubjects,
            'current_parallel' => [
                'id' => $currentStudentParallel->parallel_id,
                'paralelo' => $currentStudentParallel->parallel->paralelo,
                'turno' => $currentStudentParallel->parallel->turno,
                'course' => $currentStudentParallel->parallel->course->name,
                'level' => $currentStudentParallel->parallel->course->level,
            ],
        ];
    }

    public function addCareer(Request $request)
    {
        $validated = $request->validate([
            'student_id'  => ['required', 'exists:students,id'],
            'career_id'   => ['required', 'exists:careers,id'],
            'parallel_id' => ['required', 'exists:parallels,id'],
            'convalidation_type' => ['nullable', 'in:BTH,Tecnico_Medio'],
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
                    'message' => 'El estudiante ya está inscrito en esta carrera.'
                ], 409);
            }

            $career = Career::findOrFail($validated['career_id']);

            $studentCareer = StudentCareer::create([
                'student_id' => $validated['student_id'],
                'career_id'  => $validated['career_id'],
                'enrolled'   => now(),
                'matricula'  => $this->generateMatricula($career, $user),
            ]);

            $parallel = Parallel::findOrFail($validated['parallel_id']);
            StudentParallel::create([
                'student_id' => $validated['student_id'],
                'parallel_id' => $validated['parallel_id'],
            ]);

            // Determinar nivel de inicio según convalidación
            $startLevel = 1;
            if (!empty($validated['convalidation_type'])) {
                $startLevel = $this->calculateConvalidationStartLevel(
                    $validated['convalidation_type'],
                    $career
                );

                // Registrar convalidación
                StudentConvalidation::create([
                    'student_id' => $validated['student_id'],
                    'career_id' => $validated['career_id'],
                    'type' => $validated['convalidation_type'],
                    'start_level' => $startLevel,
                ]);
            }

            // Asignar materias DESDE el nivel de inicio
            $subjects = Subject::where('career_id', $validated['career_id'])
                ->where('level', '>=', $startLevel)
                ->orderBy('level')
                ->get();

            foreach ($subjects as $s) {
                StudentSubject::create([
                    'student_id' => $validated['student_id'],
                    'subject_id' => $s->id,
                    'status' => $s->level == $startLevel ? 'Registrado' : 'Falta'
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Carrera asignada correctamente.',
                'data' => $studentCareer,
                'convalidation' => !empty($validated['convalidation_type']) ? [
                    'type' => $validated['convalidation_type'],
                    'start_level' => $startLevel,
                ] : null,
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

    /**
     * Bloquear/activar el acceso del estudiante al sistema.
     */
    public function toggleStatus(Student $student)
    {
        try {
            $user = $student->user;

            if (!$user) {
                return response()->json([
                    'message' => 'El estudiante no tiene usuario asociado.',
                ], 422);
            }

            if ($user->id === auth()->id()) {
                return response()->json([
                    'message' => 'No puede bloquear su propio acceso.',
                ], 422);
            }

            $user->status = $user->status ? 0 : 1;

            if ((int) $user->status !== 1) {
                $user->tokens()->delete();
            }

            $user->save();

            return response()->json([
                'message' => $user->status ? 'Estudiante activado.' : 'Estudiante bloqueado.',
                'status'  => (int) $user->status,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ocurrió un error al cambiar el estado del estudiante.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function workshopEnrollments(Student $student)
    {
        try {
            $enrollments = \App\Models\WorkshopEnrollment::where('student_id', $student->id)
                ->with('edition.workshop')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json(['enrollments' => $enrollments]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener inscripciones', 'error' => $e->getMessage()], 500);
        }
    }

    public function myWorkshops()
    {
        try {
            $user = auth()->user();
            $student = \App\Models\Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json(['enrollments' => []]);
            }

            $enrollments = \App\Models\WorkshopEnrollment::where('student_id', $student->id)
                ->with('edition.workshop.modules')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json(['enrollments' => $enrollments]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener inscripciones', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Genera el número de matrícula con el formato:
     * [Inicial Carrera][Nº Students 3 dígitos]-[Año 2 dígitos]-[Iniciales APM+Nombre]
     * Ejemplo: I015-26-PGJ
     */
    private function generateMatricula(Career $career, User $user): string
    {
        // Inicial de la carrera (primera letra en mayúscula)
        $careerInitial = strtoupper(mb_substr($career->name, 0, 1));

        // Número secuencial de students con padding de 3 dígitos
        $studentCount = Student::count() + 1;
        $number = str_pad($studentCount, 3, '0', STR_PAD_LEFT);

        // Año actual - últimos 2 dígitos
        $year = substr(now()->format('Y'), -2);

        // Iniciales del estudiante: APM + AP + Nombre (en mayúsculas)
        $initials = strtoupper(
            mb_substr($user->first_lastname, 0, 1) .
            mb_substr($user->second_lastname ?? '', 0, 1) .
            mb_substr($user->name, 0, 1)
        );

        return "{$careerInitial}{$number}-{$year}-{$initials}";
    }

    /**
     * Obtiene las materias de la carrera del estudiante con su estado actual.
     * Útil para la asignación manual de materias antes de avanzar de nivel.
     */
    public function getCareerSubjects(Request $request, Student $student)
    {
        try {
            $careerId = $request->validate(['career_id' => ['required', 'exists:careers,id']]);

            $subjects = Subject::where('career_id', $careerId['career_id'])->orderBy('level')->orderBy('name')->get();

            $studentSubjects = StudentSubject::where('student_id', $student->id)
                ->whereIn('subject_id', $subjects->pluck('id'))
                ->get()
                ->keyBy('subject_id');

            $publishedGrades = Qualification::where('student_id', $student->id)
                ->where('published', true)
                ->whereIn('subject_id', $subjects->pluck('id'))
                ->get()
                ->keyBy('subject_id');

            $result = $subjects->map(function ($subject) use ($studentSubjects, $publishedGrades) {
                $ss = $studentSubjects->get($subject->id);
                $qual = $publishedGrades->get($subject->id);
                $finalGrade = $qual?->final_grade;
                $recoveryGrade = $qual?->recovery_grade;
                $passed = $finalGrade !== null && $finalGrade >= 61;
                $passedWithRecovery = $recoveryGrade !== null && $recoveryGrade >= 51 && !$passed;
                $observation = null;
                if ($ss) {
                    if ($ss->status === 'Aprobado') {
                        $observation = 'Aprobado';
                    } elseif ($passedWithRecovery) {
                        $observation = 'Aprobado con recuperación';
                    } elseif ($ss->status === 'Reprobado' || ($ss->status === 'Registrado' && $finalGrade !== null && $finalGrade < 61 && !$passedWithRecovery)) {
                        $observation = 'Reprobado';
                    } elseif ($ss->status === 'Registrado' && ($qual === null || $finalGrade === null)) {
                        $observation = 'Sin calificación';
                    } else {
                        $observation = $ss->status;
                    }
                } else {
                    $observation = 'No inscrito';
                }

                return [
                    'id' => $subject->id,
                    'sigla' => $subject->sigla,
                    'name' => $subject->name,
                    'level' => $subject->level,
                    'prerequisite_id' => $subject->subject_id,
                    'enrolled' => $ss ? true : false,
                    'status' => $ss ? $ss->status : 'No inscrito',
                    'final_grade' => $finalGrade,
                    'recovery_grade' => $recoveryGrade,
                    'observation' => $observation,
                    'has_published_grade' => $qual ? true : false,
                ];
            });

            return response()->json(['subjects' => $result]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener materias', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Asigna o cambia el estado de materias de un estudiante de forma manual.
     * El admin puede forzar el estado de una materia para permitir el avance.
     */
    public function assignSubjects(Request $request, Student $student)
    {
        try {
            $validated = $request->validate([
                'career_id' => ['required', 'exists:careers,id'],
                'subjects' => ['required', 'array'],
                'subjects.*.subject_id' => ['required', 'exists:subjects,id'],
                'subjects.*.status' => ['required', 'in:Registrado,Falta'],
            ]);

            DB::beginTransaction();

            foreach ($validated['subjects'] as $item) {
                StudentSubject::updateOrCreate(
                    ['student_id' => $student->id, 'subject_id' => $item['subject_id']],
                    ['status' => $item['status']]
                );
            }

            DB::commit();

            return response()->json(['message' => 'Materias actualizadas correctamente.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al asignar materias', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtiene el detalle de calificaciones de un estudiante por carrera.
     * Retorna todas las materias del nivel actual con sus notas, estado y observación.
     */
    public function getStudentGradesDetail(Request $request, Student $student)
    {
        try {
            $validated = $request->validate([
                'career_id' => ['required', 'exists:careers,id'],
            ]);

            $career = Career::findOrFail($validated['career_id']);

            $currentStudentParallel = StudentParallel::where('student_id', $student->id)
                ->where('status', true)
                ->whereHas('parallel.course', function ($query) use ($career) {
                    $query->where('career_id', $career->id);
                })
                ->with('parallel.course')
                ->first();

            if (!$currentStudentParallel) {
                return response()->json(['message' => 'El estudiante no tiene paralelo activo en esta carrera.'], 422);
            }

            $currentLevel = (int) $currentStudentParallel->parallel->course->level;

            $careerSubjects = Subject::where('career_id', $career->id)->get();
            $careerSubjectIds = $careerSubjects->pluck('id');

            $studentSubjects = StudentSubject::where('student_id', $student->id)
                ->whereIn('subject_id', $careerSubjectIds)
                ->get()
                ->keyBy('subject_id');

            $publishedGrades = Qualification::where('student_id', $student->id)
                ->where('published', true)
                ->whereIn('subject_id', $careerSubjectIds)
                ->with('details.evaluationColumn')
                ->get()
                ->keyBy('subject_id');

            $result = $careerSubjects->map(function ($cs) use ($studentSubjects, $publishedGrades, $currentLevel) {
                $ss = $studentSubjects->get($cs->id);
                $qual = $publishedGrades->get($cs->id);

                $finalGrade = $qual?->final_grade;
                $recoveryGrade = $qual?->recovery_grade;

                $hasGrade = $finalGrade !== null;
                $passed = $hasGrade && $finalGrade >= 61;
                $passedWithRecovery = $qual && !$passed && $recoveryGrade !== null && $recoveryGrade >= 51;

                if (!$ss) {
                    $observation = 'No inscrito';
                } elseif ($passed) {
                    $observation = 'Aprobado';
                } elseif ($passedWithRecovery) {
                    $observation = 'Aprobado con recuperación';
                } elseif ($hasGrade) {
                    $observation = 'Reprobado';
                } else {
                    $observation = 'Sin calificación';
                }

                // Detalle de evaluaciones (columnas de nota)
                $details = [];
                if ($qual && $qual->details) {
                    $details = $qual->details->map(function ($d) {
                        return [
                            'evaluation' => $d->evaluationColumn?->name,
                            'type' => $d->evaluationColumn?->type,
                            'parcial' => $d->evaluationColumn?->parcial,
                            'weight' => $d->evaluationColumn?->weight,
                            'grade' => $d->grade,
                        ];
                    })->toArray();
                }

                return [
                    'id' => $cs->id,
                    'sigla' => $cs->sigla,
                    'name' => $cs->name,
                    'level' => $cs->level,
                    'is_current_level' => $cs->level === $currentLevel,
                    'enrolled' => $ss ? true : false,
                    'status' => $ss ? $ss->status : 'No inscrito',
                    'final_grade' => $finalGrade,
                    'recovery_grade' => $recoveryGrade,
                    'observation' => $observation,
                    'has_published_grade' => $qual ? true : false,
                    'details' => $details,
                ];
            });

            return response()->json([
                'student' => [
                    'id' => $student->id,
                    'name' => trim($student->user->name . ' ' . $student->user->first_lastname . ' ' . $student->user->second_lastname),
                ],
                'career' => [
                    'id' => $career->id,
                    'name' => $career->name,
                ],
                'current_level' => $currentLevel,
                'subjects' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener calificaciones', 'error' => $e->getMessage()], 500);
        }
    }
}
