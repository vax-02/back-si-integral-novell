<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentCareer;
use App\Models\StudentParallel;
use App\Models\Subject;
use App\Models\Docente;
use App\Models\Parallel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentSubjectController extends Controller
{
    public function mySubjects(Request $request)
    {
        try {
            $user = Auth::user();

            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'message' => 'No se encontró un estudiante asociado al usuario.',
                ], 404);
            }

            // Obtener carreras del estudiante
            $studentCareers = StudentCareer::where('student_id', $student->id)
                ->with('career')
                ->get();

            if ($studentCareers->isEmpty()) {
                return response()->json([
                    'message' => 'El estudiante no está inscrito en ninguna carrera.',
                    'careers' => [],
                    'subjects' => [],
                ]);
            }

            // Obtener paralelos del estudiante
            $studentParallels = StudentParallel::where('student_id', $student->id)
                ->with('parallel')
                ->get();

            $parallelIds = $studentParallels->pluck('parallel_id')->toArray();

            // Armar carreras con sus niveles
            $careersData = [];

            foreach ($studentCareers as $sc) {
                $career = $sc->career;
                if (!$career) continue;

                $type = $career->type; // 1 = Anual, 2 = Semestral
                $duration = (int) $career->duration;
                $totalLevels = (int) $type * (int) $duration;

                $levels = [];
                for ($i = 1; $i <= $totalLevels; $i++) {
                    $label = $this->numberLiteral($i);
                    $levels[] = [
                        'level' => $i,
                        'label' => $type == 1 ? $label . ' Año' : $label . ' Semestre',
                    ];
                }

                $careersData[] = [
                    'id' => $career->id,
                    'name' => $career->name,
                    'type' => $type,
                    'duration' => $duration,
                    'levels' => $levels,
                ];
            }

            // Filtrar subjects por career_id y level si se proporcionan
            $careerId = $request->input('career_id');
            $level = $request->input('level');

            $subjectsQuery = Subject::query();

            if ($careerId) {
                $subjectsQuery->where('career_id', $careerId);
            } else {
                // Si no hay career_id, tomar la primera carrera
                $firstCareer = $studentCareers->first();
                if ($firstCareer) {
                    $careerId = $firstCareer->career_id;
                    $subjectsQuery->where('career_id', $careerId);
                }
            }

            if ($level) {
                $subjectsQuery->where('level', $level);
            }

            $subjects = $subjectsQuery->orderBy('level')->orderBy('sigla')->get();

            // Para cada materia, buscar el docente asignado al paralelo del estudiante
            $subjectsData = $subjects->map(function ($subject) use ($parallelIds) {
                // Buscar en la tabla pivote docente_subject
                $pivot = DB::table('docente_subject')
                    ->where('subject_id', $subject->id)
                    ->whereIn('parallel_id', $parallelIds)
                    ->where('status', true)
                    ->first();

                $docente = null;
                $paralelo = '—';

                if ($pivot) {
                    $docente = Docente::with('user')->find($pivot->docente_id);
                    $parallel = Parallel::find($pivot->parallel_id);
                    $paralelo = $parallel?->paralelo ?? '—';
                }

                return [
                    'sigla' => $subject->sigla,
                    'name' => $subject->name,
                    'level' => $subject->level,
                    'paralelo' => $paralelo,
                    'docente' => $docente ? trim(
                        ($docente->user->name ?? '') . ' ' .
                        ($docente->user->first_lastname ?? '') . ' ' .
                        ($docente->user->second_lastname ?? '')
                    ) : 'Sin asignar',
                ];
            });

            return response()->json([
                'careers' => $careersData,
                'subjects' => $subjectsData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener las materias del estudiante.',
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