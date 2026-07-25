<?php

namespace App\Http\Controllers;

use App\Models\EvaluationColumn;
use App\Models\Qualification;
use App\Models\QualificationDetail;
use App\Models\Student;
use App\Models\Parallel;
use App\Models\StudentParallel;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradeController extends Controller
{
    /**
     * Obtener estudiantes de un paralelo con sus calificaciones
     */
    public function getStudents(Request $request, int $parallelId)
    {
        try {
            $parallel = Parallel::with('course')->findOrFail($parallelId);
            $subjectId = $request->input('subject_id');

            // Obtener estudiantes del paralelo a través de student_parallels
            $studentIds = StudentParallel::where('parallel_id', $parallelId)
                ->where('status', true)
                ->pluck('student_id');

            $students = Student::whereIn('id', $studentIds)
                ->with('user')
                ->get();

            // Obtener columnas de evaluación
            $columns = EvaluationColumn::where('subject_id', $subjectId)
                ->where('parallel_id', $parallelId)
                ->orderBy('order')
                ->get();

            // Obtener qualifications del paralelo (un registro por estudiante+materia+curso+paralelo)
            $qualifications = Qualification::where('subject_id', $subjectId)
                ->where('course_id', $parallel->course_id)
                ->where('parallel_id', $parallelId)
                ->with('details')
                ->get()
                ->keyBy('student_id');

            $studentsData = $students->map(function ($student) use ($columns, $qualifications, $parallel, $subjectId) {
                $qual = $qualifications->get($student->id);
                $grades = [];
                $finalGrade = $qual ? $qual->final_grade : null;

                foreach ($columns as $col) {
                    $detail = $qual?->details->firstWhere('evaluation_column_id', $col->id);
                    $grades[$col->id] = [
                        'id' => $detail?->id,
                        'grade' => $detail?->grade,
                    ];
                }

                return [
                    'id' => $student->id,
                    'name' => $student->user->name . ' ' . $student->user->first_lastname,
                    'ci' => $student->user->ci,
                    'grades' => $grades,
                    'final_grade' => $finalGrade !== null ? round($finalGrade, 2) : null,
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
     * Guardar/actualizar una calificación (instantáneo al salir del input)
     */
    public function saveGrade(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'course_id' => 'required|integer|exists:courses,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
            'evaluation_column_id' => 'required|integer|exists:evaluation_columns,id',
            'grade' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            DB::beginTransaction();

            // Buscar o crear el registro principal de qualification
            $qualification = Qualification::firstOrCreate(
                [
                    'student_id' => $request->student_id,
                    'subject_id' => $request->subject_id,
                    'course_id' => $request->course_id,
                    'parallel_id' => $request->parallel_id,
                ],
                [
                    'qualification' => 0,
                    'final_grade' => null,
                ]
            );

            // Crear o actualizar el detalle (qualification_detail)
            QualificationDetail::updateOrCreate(
                [
                    'qualification_id' => $qualification->id,
                    'evaluation_column_id' => $request->evaluation_column_id,
                ],
                [
                    'grade' => $request->grade,
                ]
            );

            // Recalcular nota final
            $allColumns = EvaluationColumn::where('subject_id', $request->subject_id)
                ->where('parallel_id', $request->parallel_id)
                ->get();

            $allDetails = QualificationDetail::where('qualification_id', $qualification->id)
                ->get()
                ->keyBy('evaluation_column_id');

            $finalGrade = 0;
            foreach ($allColumns as $col) {
                $detail = $allDetails->get($col->id);
                if ($detail && $detail->grade !== null) {
                    $finalGrade += $detail->grade * $col->weight;
                }
            }

            $qualification->update([
                'final_grade' => round($finalGrade, 2),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Calificación guardada.',
                'qualification_id' => $qualification->id,
                'final_grade' => round($finalGrade, 2),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Crear columna de evaluación
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
            QualificationDetail::where('evaluation_column_id', $id)->delete();
            $column->delete();

            return response()->json(['message' => 'Columna eliminada.']);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar columna (nombre, peso, orden)
     */
    public function updateColumn(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0|max:1',
            'order' => 'nullable|integer|min:0',
        ]);

        try {
            $column = EvaluationColumn::findOrFail($id);
            $data = [
                'name' => $request->name,
                'weight' => $request->weight,
            ];
            if ($request->has('order')) {
                $data['order'] = $request->order;
            }
            $column->update($data);

            return response()->json([
                'message' => 'Columna actualizada.',
                'column' => $column,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}