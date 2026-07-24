<?php

namespace App\Http\Controllers;

use App\Models\Parallel;
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
                'user:id,name,first_lastname,second_lastname,email,ci,status',
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
            'cellphone' => ['nullable', 'string', 'max:8'],

            // Student
            'career_id' => ['required', 'exists:careers,id'],
            'birth_certificate' => ['required'],
            'school_diploma' => ['required'],
            'carnet' => ['required'],
            'parallel_id' => ['required', 'exists:parallels,id'],
        ]);

        DB::beginTransaction();

        try {

            $user = User::create([
                'name' => $validated['name'],
                'first_lastname' => $validated['first_lastname'],
                'second_lastname' => $validated['second_lastname'] ?? null,
                'ci' => $validated['ci'],
                'email' => $validated['email'],
                'cellphone' => $validated['cellphone'] ?? null,
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
                if($s->level == 1){
                    StudentSubject::create([
                        'student_id' => $student->id,
                        'subject_id' => $s->id,
                    ]); 
                }
                StudentSubject::create([
                    'student_id' => $student->id,
                    'subject_id' => $s->id,
                    'status' => 'Falta'
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
            ]);

            return response()->json([
                'student' => $student
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
            'cellphone' => ['nullable', 'string', 'max:8'],
            'birth_certificate' => ['required', 'boolean'],
            'school_diploma' => ['required', 'boolean'],
            'carnet' => ['required', 'boolean'],
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

    public function withdraw(Request $request, Student $student, $career)
    {
        try {
            $studentCareer = StudentCareer::where('student_id', $student->id)
                ->where('career_id', $career)
                ->firstOrFail();

            $studentCareer->update(['status' => 'Suspendido']);

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

        try {
            $parallel = Parallel::findOrFail($validated['parallel_id']);

            // Actualizar o crear el registro de paralelo del estudiante
            StudentParallel::updateOrCreate(
                ['student_id' => $student->id],
                [
                    'parallel_id' => $parallel->id,
                    'turno' => $parallel->turno,
                ]
            );

            return response()->json([
                'message' => 'Paralelo actualizado correctamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Error al actualizar paralelo: ' . $e->getMessage()
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
                    'message' => 'El estudiante ya está inscrito en esta carrera.'
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
                'turno'=> $parallel->turno
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
