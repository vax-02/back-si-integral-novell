<?php

namespace App\Http\Controllers;

use App\Models\EvaluationColumn;
use App\Models\Qualification;
use App\Models\Student;
use App\Models\Docente;
use App\Models\Parallel;
use App\Models\Course;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradeController extends Controller
{
    /**
     * Obtener estudiantes de un paralelo con sus calificaciones
     */
    public function getStudents(Request $request, $parallelId)
    {
        try {
            $parallel = Parallel::with('course')->findOrFail($parallelId);
            $subjectId = $request->input('subject_id');

            // Obtener estudiantes del paralelo (a través de student_careers o similar)
            // Asumiendo que los estudiantes están relacionados con cursos/paralelos
            $students = Student::whereHas('studentCareers', function ($q) use ($parallel) {
                $q->where('course_id', $parallel->course_id);
            })->with('user')->get();

            // Obtener columnas de evaluación
            $columns = EvaluationColumn::where('subject_id', $subjectId)
                ->where('parallel_id', $parallelId)
                ->orderBy('order')
                ->get();

            // Obtener calificaciones existentes
            $qualifications = Qualification::where('subject_id', $subjectId)
                ->where('course_id', $parallel->course_id)
                ->whereIn('evaluation_column_id', $columns->pluck('id'))
                ->get()
                ->groupBy('student_id');

            $studentsData = $students->map(function ($student) use ($columns, $qualifications, $parallel, $subjectId) {
                $studentQuals = $qualifications->get($student->id, collect());
                $grades = [];
                $finalGrade = 0;

                foreach ($columns as $col) {
                    $qual = $studentQuals->firstWhere('evaluation_column_id', $col->id);
                    $grade = $qual ? $qual->qualification : null;
                    $grades[$col->id] = [
                        'id' => $qual ? $qual->id : null,
                        'grade' => $grade,
                    ];
                    if ($grade !== null) {
                        $finalGrade += $grade * $col->weight;
                    }
                }

                return [
                    'id' => $student->id,
                    'name' => $student->user->name . ' ' . $student->user->first_lastname,
                    'ci' => $student->user->ci,
                    'grades' => $grades,
                    'final_grade' => round($finalGrade, 2),
                ];
            });

            return response()->json([
                'students' => $studentsData,
                'columns' => $columns,
                'parallel' => $parallel->load('course.career'),
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Guardar/actualizar una calificación individual (auto-save on blur)
     */
    public function saveGrade(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'course_id' => 'required|integer|exists:courses,id',
            'evaluation_column_id' => 'required|integer|exists:evaluation_columns,id',
            'qualification' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $qual = Qualification::updateOrCreate(
                [
                    'student_id' => $request->student_id,
                    'course_id' => $request->course_id,
                    'subject_id' => $request->subject_id,
                    'evaluation_column_id' => $request->evaluation_column_id,
                ],
                [
                    'qualification' => $request->qualification ?? 0,
                ]
            );

            // Calcular nota final actualizada
            $column = EvaluationColumn::find($request->evaluation_column_id);
            $allColumns = EvaluationColumn::where('subject_id', $request->subject_id)
                ->where('parallel_id', $column->parallel_id)
                ->get();

            $allQuals = Qualification::where('student_id', $request->student_id)
                ->where('subject_id', $request->subject_id)
                ->where('course_id', $request->course_id)
                ->whereIn('evaluation_column_id', $allColumns->pluck('id'))
                ->get()
                ->keyBy('evaluation_column_id');

            $finalGrade = 0;
            foreach ($allColumns as $col) {
                $q = $allQuals->get($col->id);
                if ($q && $q->qualification !== null) {
                    $finalGrade += $q->qualification * $col->weight;
                }
            }

            return response()->json([
                'message' => 'Calificación guardada.',
                'qualification' => $qual,
                'final_grade' => round($finalGrade, 2),
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Crear/actualizar columna de evaluación
     */
    public function saveColumn(Request $request)
    {
        $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
            'course_id' => 'required|integer|exists:courses,id',
            'name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0|max:1',
            'order' => 'integer|min:0',
        ]);

        try {
            $column = EvaluationColumn::create([
                'subject_id' => $request->subject_id,
                'parallel_id' => $request->parallel_id,
                'course_id' => $request->course_id,
                'name' => $request->name,
                'weight' => $request->weight,
                'order' => $request->order ?? 0,
            ]);

            return response()->json([
                'message' => 'Columna creada.',
                'column' => $column,
            ], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar columna de evaluación
     */
    public function deleteColumn($id)
    {
        try {
            $column = EvaluationColumn::findOrFail($id);
            // Eliminar calificaciones asociadas
            Qualification::where('evaluation_column_id', $id)->delete();
            $column->delete();

            return response()->json(['message' => 'Columna eliminada.']);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar columna (nombre, peso)
     */
    public function updateColumn(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0|max:1',
        ]);

        try {
            $column = EvaluationColumn::findOrFail($id);
            $column->update([
                'name' => $request->name,
                'weight' => $request->weight,
            ]);

            return response()->json([
                'message' => 'Columna actualizada.',
                'column' => $column,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}