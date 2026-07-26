<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use App\Models\Student;
use App\Models\Qualification;
use App\Models\EvaluationColumn;
use App\Models\StudentParallel;
use App\Models\StudentSubject;
use App\Models\Subject;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentGradeController extends Controller
{
    /**
     * Obtener las calificaciones publicadas del estudiante logueado
     */
    public function myGrades(Request $request)
    {
        try {
            $user = Auth::user();

            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json(['message' => 'Estudiante no encontrado'], 404);
            }

            // Obtener los paralelos del estudiante
            $studentParallels = StudentParallel::where('student_id', $student->id)
                ->where('status', true)
                ->get();

            if ($studentParallels->isEmpty()) {
                return response()->json(['grades' => []]);
            }

            $sigla = $request->input('sigla');

            // Construir query para qualifications publicadas
            $query = Qualification::where('student_id', $student->id)
                ->where('published', true)
                ->with(['subject', 'parallel.course', 'details.evaluationColumn']);

            if ($sigla) {
                $query->whereHas('subject', function ($q) use ($sigla) {
                    $q->where('sigla', $sigla);
                });
            }
            
            $qualifications = $query->get();

            
            $gradesData = $qualifications->map(function ($qual) {
                $columns = EvaluationColumn::where('subject_id', $qual->subject_id)
                ->where('parallel_id', $qual->parallel_id)
                ->orderBy('order')
                ->get();
                
                $details = $qual->details->keyBy('evaluation_column_id');

                $evaluations = $columns->map(function ($col) use ($details) {
                    $detail = $details->get($col->id);
                    return [
                        'name' => $col->name,
                        'weight' => $col->weight,
                        'weight_percent' => $col->weight * 100,
                        'grade' => $detail ? $detail->grade : null,
                    ];
                });
                        
                $docenteParallel = Docente::whereHas('subjects', function ($q) use ($qual) {
                    $q->where('subjects.id', $qual->subject_id)
                     ->where('parallel_id', $qual->parallel_id);
                })->with('user')->first();

                return [
                    'subject_id' => $qual->subject_id,
                    'subject_name' => $qual->subject?->name,
                    'subject_sigla' => $qual->subject?->sigla,
                    'parallel_name' => $qual->parallel?->paralelo,
                    'turno' => $qual->parallel?->turno,
                    'course_name' => $qual->parallel?->course?->name,
                    'docente' => $docenteParallel ? trim(
                        ($docenteParallel->user->name ?? '') . ' ' .
                        ($docenteParallel->user->first_lastname ?? '') . ' ' .
                        ($docenteParallel->user->second_lastname ?? '')
                    ) : '—',
                    'evaluations' => $evaluations,
                    'final_grade' => $qual->final_grade,
                ];
            });


            return response()->json([
                'grades' => $gradesData,
            ]);

        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}