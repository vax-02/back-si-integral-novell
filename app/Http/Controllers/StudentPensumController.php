<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentCareer;
use App\Models\StudentSubject;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentPensumController extends Controller
{
    public function myPensum()
    {
        try {
            $user = Auth::user();

            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'message' => 'No se encontró un estudiante asociado al usuario.',
                ], 404);
            }

            $studentCareers = StudentCareer::where('student_id', $student->id)
                ->with('career')
                ->get();

            if ($studentCareers->isEmpty()) {
                return response()->json([
                    'message' => 'El estudiante no está inscrito en ninguna carrera.',
                    'careers' => [],
                ]);
            }

            $careersData = [];

            foreach ($studentCareers as $sc) {
                $career = $sc->career;
                if (!$career) continue;

                $subjects = Subject::where('career_id', $career->id)
                    ->with('prerequisite')
                    ->orderBy('level')
                    ->get();

                $subjectsCount = $subjects->count();
                $duration = $career->duration;
                $type = $career->type;

                // Determinar etiquetas según tipo
                // type 1 = Anual (niveles 1,2,3... = Primer Año, Segundo Año...)
                // type 2 = Semestral (niveles 1,2,3... = Primer Semestre, Segundo Semestre...)
                $levelLabels = [];
                $cantidadNiveles = (int)$type * (int)$duration;
                for ($i = 1; $i <= $cantidadNiveles; $i++) {
                    $label = $this->numberLiteral($i);
                    if ($type == 1) {
                        $levelLabels[$i] = $label . ' Año';
                    } else {
                        $levelLabels[$i] = $label . ' Semestre';
                    }
                }

                // Agrupar materias por nivel
                $grouped = $subjects->groupBy('level')->map(function ($items, $level) use ($student, $levelLabels) {
                    return [
                        'level' => (int) $level,
                        'label' => $levelLabels[(int) $level] ?? 'Nivel ' . $level,
                        'subjects' => $items->map(function ($subject) use ($student) {
                            // Obtener estado desde student_subjects
                            $studentSubject = StudentSubject::where('student_id', $student->id)
                                ->where('subject_id', $subject->id)
                                ->first();

                            return [
                                'sigla' => $subject->sigla,
                                'name' => $subject->name,
                                'prerequisite_sigla' => $subject->prerequisite?->sigla,
                                'status' => $studentSubject?->status ?? 'Sin asignar',
                            ];
                        }),
                    ];
                })->values();

                $careersData[] = [
                    'id' => $career->id,
                    'name' => $career->name,
                    'duration' => $duration,
                    'type' => $type,
                    'total_subjects' => $subjectsCount,
                    'levels' => $grouped,
                ];
            }

            return response()->json([
                'careers' => $careersData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener el pensum del estudiante.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function numberLiteral($number)
    {
        switch ($number) {
            case 1: return 'Primer';
            case 2: return 'Segundo';
            case 3: return 'Tercer';
            case 4: return 'Cuarto';
            case 5: return 'Quinto';
            case 6: return 'Sexto';
            default: return 'Nivel ' . $number;
        }
    }
}