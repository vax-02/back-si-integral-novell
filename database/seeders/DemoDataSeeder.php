<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Docente;
use App\Models\DocenteSchedule;
use App\Models\EvaluationColumn;
use App\Models\Parallel;
use App\Models\Qualification;
use App\Models\QualificationDetail;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentCareer;
use App\Models\StudentParallel;
use App\Models\StudentSubject;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->cleanup();

        $docentes = $this->seedDocentes();
        $cohorts = $this->seedStudents();

        $columns = $this->seedEvaluationColumns();

        $this->seedQualifications($cohorts, $columns);

        $this->seedSchedules();
        $this->seedDocenteAssignments($docentes);
        $this->seedDocenteSchedules($docentes);
    }

    private function cleanup(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('qualification_details')->delete();
        DB::table('qualifications')->delete();
        DB::table('evaluation_columns')->delete();
        DB::table('student_subjects')->delete();
        DB::table('student_parallels')->delete();
        DB::table('student_careers')->delete();
        DB::table('students')->delete();
        DB::table('schedules')->delete();
        DB::table('docente_schedules')->delete();
        DB::table('attendance_records')->delete();
        DB::table('docente_subject')->delete();
        DB::table('user_roles')->delete();
        DB::table('docentes')->delete();
        User::whereNotIn('id', [1, 53])->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        UserRoles::create(['user_id' => 1, 'role_id' => 1]);

        $this->command?->info('Limpieza completada.');
    }

    private function createUser(string $ci, string $email, string $name, string $firstLastname, ?string $secondLastname, int $roleId): User
    {
        $user = User::create([
            'name' => $name,
            'first_lastname' => $firstLastname,
            'second_lastname' => $secondLastname,
            'ci' => $ci,
            'email' => $email,
            'password' => Hash::make('123456789'),
            'cellphone' => '7000000' . random_int(1, 9),
            'status' => 1,
        ]);
        UserRoles::create(['user_id' => $user->id, 'role_id' => $roleId]);

        return $user;
    }

    private function seedDocentes(): array
    {
        // Javier (docente 1) se conserva; se le asigna pin.
        if (!User::where('id', 53)->exists()) {
            $user = new User([
                'name' => 'javier',
                'first_lastname' => 'docente',
                'second_lastname' => null,
                'ci' => '7333954',
                'email' => 'javier@demo.com',
                'password' => Hash::make('123456789'),
                'status' => 1,
            ]);
            $user->id = 53;
            $user->save();
            UserRoles::create(['user_id' => 53, 'role_id' => 3]);
        }
        $javier = Docente::create([
            'user_id' => 53,
            'degree_id' => 1,
            'cv' => 1,
            'professional_title' => 1,
            'carnet' => 1,
            'certificate' => 1,
            'biometric_pin' => '1001',
            'tolerance_minutes' => 5,
        ]);

        $nuevos = [
            ['ci' => '10000021', 'name' => 'Maria', 'first' => 'Fernandez', 'second' => 'Rojas', 'degree' => 1, 'pin' => '1002'],
            ['ci' => '10000022', 'name' => 'Carlos', 'first' => 'Mendoza', 'second' => 'Salazar', 'degree' => 2, 'pin' => '1003'],
            ['ci' => '10000023', 'name' => 'Ana', 'first' => 'Vargas', 'second' => 'Torres', 'degree' => 3, 'pin' => '1004'],
            ['ci' => '10000024', 'name' => 'Luis', 'first' => 'Rojas', 'second' => 'Quispe', 'degree' => 4, 'pin' => '1005'],
            ['ci' => '10000025', 'name' => 'Sofia', 'first' => 'Gutierrez', 'second' => 'Paredes', 'degree' => 2, 'pin' => '1006'],
        ];

        $docentes = [$javier->id => $javier];
        foreach ($nuevos as $i => $d) {
            $user = $this->createUser($d['ci'], 'docente' . (2 + $i) . '@demo.com', $d['name'], $d['first'], $d['second'], 3);
            $doc = Docente::create([
                'user_id' => $user->id,
                'degree_id' => $d['degree'],
                'cv' => 1,
                'professional_title' => 1,
                'carnet' => 1,
                'certificate' => 1,
                'biometric_pin' => $d['pin'],
                'tolerance_minutes' => 10,
            ]);
            $docentes[$doc->id] = $doc;
        }

        $this->command?->info('Docentes: ' . count($docentes));

        return $docentes;
    }

    private function seedStudents(): array
    {
        $careerId = 1;
        $courses = Course::where('career_id', $careerId)->orderBy('level')->get();
        $course1 = $courses->firstWhere('level', 1);
        $course2 = $courses->firstWhere('level', 2);
        $par1 = Parallel::where('course_id', $course1->id)->first();
        $par2 = Parallel::where('course_id', $course2->id)->first();

        $subjects1 = Subject::where('career_id', $careerId)->where('level', 1)->orderBy('id')->pluck('id')->values();
        $subjects2 = Subject::where('career_id', $careerId)->where('level', 2)->orderBy('id')->pluck('id')->values();

        $nombres = [
            ['Camila', 'Aguilar', 'Rios'],
            ['Mateo', 'Beltrán', 'Castro'],
            ['Valentina', 'Cabrera', 'Díaz'],
            ['Sebastián', 'Duran', 'Escobar'],
            ['Isabella', 'Espinoza', 'Flores'],
            ['Gabriel', 'Fuentes', 'Guzmán'],
            ['Renata', 'Herrera', 'Iglesias'],
            ['Emilio', 'Jiménez', 'Lara'],
        ];

        $cohortes = [
            ['year' => 2024, 'status' => 'Egresado', 'par1' => false, 'par2' => false],
            ['year' => 2025, 'status' => 'Activo', 'par1' => false, 'par2' => true],
            ['year' => 2026, 'status' => 'Activo', 'par1' => true, 'par2' => null],
        ];

        $cohorts = [];
        $studentNumber = 1;

        foreach ($cohortes as $cohort) {
            $year = $cohort['year'];
            $list = [];

            for ($k = 0; $k < 8; $k++) {
                [$name, $first, $second] = $nombres[$k];
                $ci = '200000' . str_pad($studentNumber, 2, '0', STR_PAD_LEFT);
                $email = 'estudiante' . str_pad($studentNumber, 2, '0', STR_PAD_LEFT) . '@demo.com';
                $user = $this->createUser($ci, $email, $name, $first, $second, 4);

                $student = Student::create([
                    'user_id' => $user->id,
                    'birth_certificate' => 1,
                    'school_diploma' => 1,
                    'carnet' => 1,
                ]);

                StudentCareer::create([
                    'student_id' => $student->id,
                    'career_id' => $careerId,
                    'enrolled' => $year . '-02-01',
                    'matricula' => 'MAT-' . $year . '-' . str_pad($studentNumber, 2, '0', STR_PAD_LEFT),
                    'status' => $cohort['status'],
                ]);

                $historial = [
                    ['parallel_id' => $par1->id, 'status' => $cohort['par1'], 'year' => $year],
                    ['parallel_id' => $par2->id, 'status' => $cohort['par2'], 'year' => $year + 1],
                ];
                foreach ($historial as $h) {
                    if ($h['status'] === null) {
                        continue;
                    }
                    $sp = StudentParallel::create([
                        'student_id' => $student->id,
                        'parallel_id' => $h['parallel_id'],
                        'status' => $h['status'],
                    ]);
                    $sp->created_at = $h['year'] . '-02-10 08:00:00';
                    $sp->updated_at = $h['year'] . '-02-10 08:00:00';
                    $sp->save();
                }

                // Materias cursadas: 1er año (y 2do para cohortes que avanzaron)
                $this->seedStudentSubjects($student, $subjects1, $year, $cohort['status'] === 'Egresado');
                if ($cohort['status'] === 'Egresado' || $cohort['year'] < 2026) {
                    $this->seedStudentSubjects($student, $subjects2, $year + 1, false);
                }

                $list[] = $student;
                $studentNumber++;
            }

            $cohorts[$year] = [
                'students' => $list,
                'course1' => $course1,
                'course2' => $course2,
                'par1' => $par1,
                'par2' => $par2,
            ];
        }

        $this->command?->info('Estudiantes: ' . ($studentNumber - 1));

        return $cohorts;
    }

    private function seedStudentSubjects(Student $student, $subjectIds, int $year, bool $aprobado): void
    {
        foreach ($subjectIds as $subjectId) {
            $ss = StudentSubject::create([
                'student_id' => $student->id,
                'subject_id' => $subjectId,
                'status' => $aprobado ? 'Aprobado' : 'Registrado',
            ]);
            $ss->created_at = $year . '-02-15 09:00:00';
            $ss->updated_at = $year . '-11-30 09:00:00';
            $ss->save();
        }
    }

    private function seedEvaluationColumns(): array
    {
        $criterio = [
            ['name' => '1er Parcial', 'weight' => 0.25, 'order' => 0],
            ['name' => '2do Parcial', 'weight' => 0.25, 'order' => 1],
            ['name' => 'Prácticas + Asistencia', 'weight' => 0.50, 'order' => 2],
        ];

        $map = [];
        $combos = [
            ['parallel_id' => 1, 'course_id' => 1, 'subjects' => Subject::where('level', 1)->orderBy('id')->pluck('id')],
            ['parallel_id' => 2, 'course_id' => 2, 'subjects' => Subject::where('level', 2)->orderBy('id')->pluck('id')],
        ];

        foreach ($combos as $combo) {
            foreach ($combo['subjects'] as $subjectId) {
                foreach ($criterio as $c) {
                    $col = EvaluationColumn::create([
                        'subject_id' => $subjectId,
                        'parallel_id' => $combo['parallel_id'],
                        'course_id' => $combo['course_id'],
                        'name' => $c['name'],
                        'weight' => $c['weight'],
                        'order' => $c['order'],
                    ]);
                    $map[$subjectId][$combo['parallel_id']][$c['name']] = $col;
                }
            }
        }

        $this->command?->info('Columnas de evaluación: ' . EvaluationColumn::count());

        return $map;
    }

    private function seedQualifications(array $cohorts, array $columns): void
    {
        $detailBatch = [];

        foreach ($cohorts as $year => $cohort) {
            $esUltima = ($year === 2026);
            $periodos = [
                ['year' => $year, 'course' => $cohort['course1'], 'parallel' => $cohort['par1'], 'level' => 1],
            ];
            if (!$esUltima) {
                $periodos[] = ['year' => $year + 1, 'course' => $cohort['course2'], 'parallel' => $cohort['par2'], 'level' => 2];
            }

            foreach ($cohort['students'] as $student) {
                foreach ($periodos as $periodo) {
                    $subjects = Subject::where('level', $periodo['level'])->orderBy('id')->get();
                    foreach ($subjects as $subject) {
                        $notas = [random_int(51, 100), random_int(51, 100), random_int(51, 100)];
                        $final = round($notas[0] * 0.25 + $notas[1] * 0.25 + $notas[2] * 0.50, 2);
                        $fecha = $periodo['year'] . '-11-' . str_pad(random_int(20, 28), 2, '0', STR_PAD_LEFT) . ' 10:00:00';

                        $qualId = DB::table('qualifications')->insertGetId([
                            'student_id' => $student->id,
                            'course_id' => $periodo['course']->id,
                            'parallel_id' => $periodo['parallel']->id,
                            'subject_id' => $subject->id,
                            'qualification' => (int) round($final),
                            'final_grade' => $final,
                            'published' => 1,
                            'created_at' => $fecha,
                            'updated_at' => $fecha,
                        ]);

                        $i = 0;
                        foreach ($columns[$subject->id][$periodo['parallel']->id] as $col) {
                            $detailBatch[] = [
                                'qualification_id' => $qualId,
                                'evaluation_column_id' => $col->id,
                                'grade' => $notas[$i],
                                'created_at' => $fecha,
                                'updated_at' => $fecha,
                            ];
                            $i++;
                        }
                    }
                }
            }
        }

        foreach (array_chunk($detailBatch, 500) as $chunk) {
            DB::table('qualification_details')->insert($chunk);
        }

        $this->command?->info('Calificaciones: ' . Qualification::count());
        $this->command?->info('Detalles: ' . QualificationDetail::count());
    }

    private function seedSchedules(): void
    {
        $dias = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
        $slots = [
            ['start' => '09:00:00', 'end' => '10:30:00'],
            ['start' => '10:30:00', 'end' => '12:00:00'],
            ['start' => '14:00:00', 'end' => '15:30:00'],
            ['start' => '15:30:00', 'end' => '17:00:00'],
            ['start' => '09:00:00', 'end' => '10:30:00'],
            ['start' => '10:30:00', 'end' => '12:00:00'],
        ];

        $combos = [
            ['parallel_id' => 1, 'subjects' => Subject::where('level', 1)->orderBy('id')->pluck('id')->values()],
            ['parallel_id' => 2, 'subjects' => Subject::where('level', 2)->orderBy('id')->pluck('id')->values()],
        ];

        foreach ($combos as $combo) {
            foreach ($combo['subjects'] as $i => $subjectId) {
                Schedule::create([
                    'parallel_id' => $combo['parallel_id'],
                    'day' => $dias[$i % 6],
                    'start_time' => $slots[$i]['start'],
                    'end_time' => $slots[$i]['end'],
                    'subject_id' => $subjectId,
                ]);
            }
        }

        $this->command?->info('Horarios: ' . Schedule::count());
    }

    private function seedDocenteAssignments(array $docentes): void
    {
        $docenteIds = array_keys($docentes);
        $idx = 0;
        $total = 0;

        foreach (range(1, 12) as $subjectId) {
            $parallelId = $subjectId <= 6 ? 1 : 2;
            $docenteId = $docenteIds[$idx % count($docenteIds)];
            $idx++;
            DB::table('docente_subject')->insert([
                'docente_id' => $docenteId,
                'subject_id' => $subjectId,
                'parallel_id' => $parallelId,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $total++;
        }

        $this->command?->info('Asignaciones docente-materia: ' . $total);
    }

    private function seedDocenteSchedules(array $docentes): void
    {
        $dias = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes'];
        foreach ($docentes as $docente) {
            foreach ($dias as $dia) {
                DocenteSchedule::create([
                    'docente_id' => $docente->id,
                    'day' => $dia,
                    'entry_time' => '08:00:00',
                    'is_active' => true,
                ]);
            }
        }

        $this->command?->info('Horarios docentes: ' . DocenteSchedule::count());
    }
}
