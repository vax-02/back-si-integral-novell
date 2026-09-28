<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\EvaluationColumn;
use App\Models\Qualification;
use App\Models\QualificationDetail;
use App\Models\Student;
use App\Models\Parallel;
use App\Models\StudentParallel;
use App\Models\Subject;
use App\Services\GradeExportService;
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

            $studentIds = StudentParallel::where('parallel_id', $parallelId)
                ->where('status', true)
                ->pluck('student_id');

            $students = Student::whereIn('id', $studentIds)
                ->with('user')
                ->get();

            $columns = EvaluationColumn::where('subject_id', $subjectId)
                ->where('parallel_id', $parallelId)
                ->orderBy('order')
                ->get();

            $qualifications = Qualification::where('subject_id', $subjectId)
                ->where('course_id', $parallel->course_id)
                ->where('parallel_id', $parallelId)
                ->with('details')
                ->get()
                ->keyBy('student_id');

            $subject = Subject::find($subjectId);

            $studentsData = $students->map(function ($student) use ($columns, $qualifications, $parallel, $subjectId, $subject) {
                $qual = $qualifications->get($student->id);
                $grades = [];

                foreach ($columns as $col) {
                    $detail = $qual?->details->firstWhere('evaluation_column_id', $col->id);
                    $grades[$col->id] = [
                        'id' => $detail?->id,
                        'grade' => $detail?->grade,
                    ];
                }

                $theoreticalAvg = null;
                $practicalAvg = null;
                $finalGrade = null;

                if ($qual) {
                    $theoreticalAvg = $this->calculateTypeAverage($columns, $qual->details, 'teorica');
                    $practicalAvg = $this->calculateTypeAverage($columns, $qual->details, 'practica');
                    $finalGrade = $this->calculateFinalGrade($theoreticalAvg, $practicalAvg, $subject);
                }

                return [
                    'id' => $student->id,
                    'name' => $student->user->name . ' ' . $student->user->first_lastname,
                    'ci' => $student->user->ci,
                    'grades' => $grades,
                    'theoretical_average' => $theoreticalAvg !== null ? round($theoreticalAvg, 2) : null,
                    'practical_average' => $practicalAvg !== null ? round($practicalAvg, 2) : null,
                    'final_grade' => $finalGrade !== null ? round($finalGrade, 2) : null,
                    'recovery_grade' => $qual?->recovery_grade,
                ];
            });

            $totalQualifications = $qualifications->count();
            $publishedQualifications = $qualifications->filter(fn($q) => $q->published)->count();
            $allPublished = $totalQualifications > 0 && $totalQualifications === $publishedQualifications;

            return response()->json([
                'students' => $studentsData,
                'columns' => $columns,
                'parallel' => $parallel->load('course.career'),
                'published' => $allPublished,
                'subject' => $subject ? [
                    'theory_weight' => $subject->theory_weight,
                    'practice_weight' => $subject->practice_weight,
                    'num_parciales' => $subject->num_parciales,
                ] : null,
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

            QualificationDetail::updateOrCreate(
                [
                    'qualification_id' => $qualification->id,
                    'evaluation_column_id' => $request->evaluation_column_id,
                ],
                [
                    'grade' => $request->grade,
                ]
            );

            $allColumns = EvaluationColumn::where('subject_id', $request->subject_id)
                ->where('parallel_id', $request->parallel_id)
                ->get();

            $allDetails = QualificationDetail::where('qualification_id', $qualification->id)
                ->get()
                ->keyBy('evaluation_column_id');

            $subject = Subject::find($request->subject_id);

            $theoreticalAvg = $this->calculateTypeAverage($allColumns, $allDetails->values(), 'teorica');
            $practicalAvg = $this->calculateTypeAverage($allColumns, $allDetails->values(), 'practica');
            $finalGrade = $this->calculateFinalGrade($theoreticalAvg, $practicalAvg, $subject);

            $qualification->update([
                'final_grade' => $finalGrade !== null ? round($finalGrade, 2) : null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Calificación guardada.',
                'qualification_id' => $qualification->id,
                'theoretical_average' => $theoreticalAvg !== null ? round($theoreticalAvg, 2) : null,
                'practical_average' => $practicalAvg !== null ? round($practicalAvg, 2) : null,
                'final_grade' => $finalGrade !== null ? round($finalGrade, 2) : null,
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
            'type' => 'required|in:teorica,practica',
            'parcial' => 'required|integer|min:1|max:4',
            'weight' => 'required|numeric|min:0|max:1',
            'order' => 'integer|min:0',
        ]);

        try {
            $column = EvaluationColumn::create([
                'subject_id' => $request->subject_id,
                'parallel_id' => $request->parallel_id,
                'course_id' => $request->course_id,
                'name' => $request->name,
                'type' => $request->type,
                'parcial' => $request->parcial,
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
            QualificationDetail::where('evaluation_column_id', $id)->delete();
            $column->delete();

            return response()->json(['message' => 'Columna eliminada.']);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Publicar notas de una materia+paralelo
     */
    public function publish(Request $request)
    {
        $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
        ]);

        try {
            Qualification::where('subject_id', $request->subject_id)
                ->where('parallel_id', $request->parallel_id)
                ->update(['published' => true]);

            // Verificar si todas las materias del paralelo están publicadas
            // y ejecutar avance automático
            $parallel = \App\Models\Parallel::with('course')->find($request->parallel_id);
            $autoAdvanceResult = null;

            if ($parallel) {
                $career = $parallel->course->career;
                $careerSubjectIds = \App\Models\Subject::where('career_id', $career->id)->pluck('id');

                $totalSubjects = $careerSubjectIds->count();
                $publishedCount = \App\Models\Qualification::where('parallel_id', $request->parallel_id)
                    ->whereIn('subject_id', $careerSubjectIds)
                    ->where('published', true)
                    ->distinct('subject_id')
                    ->count('subject_id');

                // Si todas las materias están publicadas, ejecutar avance automático
                if ($publishedCount >= $totalSubjects && $totalSubjects > 0) {
                    $studentController = new \App\Http\Controllers\StudentController();
                    $autoAdvanceResult = $studentController->processAutoAdvance($parallel);
                }
            }

            return response()->json([
                'message' => 'Notas publicadas correctamente.',
                'published' => true,
                'auto_advance' => $autoAdvanceResult,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Despublicar notas de una materia+paralelo
     */
    public function unpublish(Request $request)
    {
        $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
        ]);

        try {
            Qualification::where('subject_id', $request->subject_id)
                ->where('parallel_id', $request->parallel_id)
                ->update(['published' => false]);

            return response()->json([
                'message' => 'Notas despublicadas correctamente.',
                'published' => false,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar columna (nombre, peso, orden, tipo, parcial)
     */
    public function updateColumn(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'weight' => 'required|numeric|min:0|max:1',
            'order' => 'nullable|integer|min:0',
            'type' => 'sometimes|in:teorica,practica',
            'parcial' => 'sometimes|integer|min:1|max:4',
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
            if ($request->has('type')) {
                $data['type'] = $request->type;
            }
            if ($request->has('parcial')) {
                $data['parcial'] = $request->parcial;
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

    /**
     * Guardar nota de recuperación
     */
    public function saveRecoveryGrade(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'course_id' => 'required|integer|exists:courses,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
            'recovery_grade' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $qualification = Qualification::where('student_id', $request->student_id)
                ->where('subject_id', $request->subject_id)
                ->where('course_id', $request->course_id)
                ->where('parallel_id', $request->parallel_id)
                ->first();

            if (!$qualification) {
                return response()->json(['error' => 'No existe calificación para este estudiante.'], 404);
            }

            $qualification->update([
                'recovery_grade' => $request->recovery_grade,
            ]);

            return response()->json([
                'message' => 'Nota de recuperación guardada.',
                'recovery_grade' => $request->recovery_grade,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar configuración de la materia (pesos teoría/práctica, número de parciales)
     */
    public function updateSubjectConfig(Request $request)
    {
        $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
            'theory_weight' => 'required|numeric|min:0|max:1',
            'practice_weight' => 'required|numeric|min:0|max:1',
            'num_parciales' => 'required|integer|min:1|max:4',
        ]);

        try {
            $subject = Subject::findOrFail($request->subject_id);
            $subject->update([
                'theory_weight' => $request->theory_weight,
                'practice_weight' => $request->practice_weight,
                'num_parciales' => $request->num_parciales,
            ]);

            return response()->json([
                'message' => 'Configuración de materia actualizada.',
                'subject' => [
                    'theory_weight' => $subject->theory_weight,
                    'practice_weight' => $subject->practice_weight,
                    'num_parciales' => $subject->num_parciales,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Informe de parciales por materia
     */
    public function parcialReport(Request $request)
    {
        $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
        ]);

        try {
            $parallel = Parallel::with('course.career')->findOrFail($request->parallel_id);
            $subject = Subject::findOrFail($request->subject_id);

            $columns = EvaluationColumn::where('subject_id', $subject->id)
                ->where('parallel_id', $parallel->id)
                ->orderBy('parcial')
                ->orderBy('order')
                ->get();

            $columnsByParcial = $columns->groupBy('parcial');

            $studentIds = StudentParallel::where('parallel_id', $parallel->id)
                ->where('status', true)
                ->pluck('student_id');

            $students = Student::whereIn('id', $studentIds)
                ->with('user')
                ->get();

            $qualifications = Qualification::where('subject_id', $subject->id)
                ->where('course_id', $parallel->course_id)
                ->where('parallel_id', $parallel->id)
                ->with('details')
                ->get()
                ->keyBy('student_id');

            $reportData = $students->map(function ($student) use ($columnsByParcial, $qualifications, $subject) {
                $qual = $qualifications->get($student->id);
                $parciales = [];

                foreach ($columnsByParcial as $parcialNum => $parcialCols) {
                    $theoSum = 0;
                    $theoWeightSum = 0;
                    $praSum = 0;
                    $praWeightSum = 0;

                    foreach ($parcialCols as $col) {
                        $detail = $qual?->details->firstWhere('evaluation_column_id', $col->id);
                        $grade = $detail?->grade;

                        if ($grade !== null) {
                            if ($col->type === 'teorica') {
                                $theoSum += $grade * $col->weight;
                                $theoWeightSum += $col->weight;
                            } else {
                                $praSum += $grade * $col->weight;
                                $praWeightSum += $col->weight;
                            }
                        }
                    }

                    $theoAvg = $theoWeightSum > 0 ? round($theoSum / $theoWeightSum, 2) : null;
                    $praAvg = $praWeightSum > 0 ? round($praSum / $praWeightSum, 2) : null;

                    $parcialFinal = null;
                    if ($theoAvg !== null && $praAvg !== null) {
                        $parcialFinal = round(
                            ($theoAvg * $subject->theory_weight) + ($praAvg * $subject->practice_weight),
                            2
                        );
                    } elseif ($theoAvg !== null) {
                        $parcialFinal = $theoAvg;
                    } elseif ($praAvg !== null) {
                        $parcialFinal = $praAvg;
                    }

                    $parciales[$parcialNum] = [
                        'theoretical_average' => $theoAvg,
                        'practical_average' => $praAvg,
                        'final_grade' => $parcialFinal,
                    ];
                }

                $promedioFinal = null;
                $validParciales = array_filter($parciales, fn($p) => $p['final_grade'] !== null);
                if (count($validParciales) > 0) {
                    $sum = array_sum(array_map(fn($p) => $p['final_grade'], $validParciales));
                    $promedioFinal = round($sum / count($validParciales), 2);
                }

                $observation = null;
                $effectiveGrade = $promedioFinal;
                $passedWithRecovery = $qual?->recovery_grade !== null && $promedioFinal !== null && $promedioFinal < 61 && $qual->recovery_grade >= 51;
                if ($passedWithRecovery) {
                    $effectiveGrade = $qual->recovery_grade;
                }
                if ($effectiveGrade !== null) {
                    $observation = $effectiveGrade >= 61 ? 'Aprobado' : 'Reprobado';
                    if ($passedWithRecovery) {
                        $observation = 'Aprobado con recuperación';
                    }
                } else {
                    $observation = 'Abandono';
                }

                return [
                    'id' => $student->id,
                    'name' => trim(($student->user->name ?? '') . ' ' . ($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '')),
                    'ci' => $student->user->ci ?? '',
                    'parciales' => $parciales,
                    'promedio_final' => $promedioFinal,
                    'recovery_grade' => $qual?->recovery_grade,
                    'observation' => $observation,
                ];
            });

            $totalStudents = $reportData->count();
            $aprobados = $reportData->where('observation', 'Aprobado')->count();
            $reprobados = $reportData->where('observation', 'Reprobado')->count();
            $promedioGeneral = $reportData->where('promedio_final', '!==', null)
                ->avg('promedio_final');

            return response()->json([
                'subject' => [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'sigla' => $subject->sigla,
                    'theory_weight' => $subject->theory_weight,
                    'practice_weight' => $subject->practice_weight,
                    'num_parciales' => $subject->num_parciales,
                ],
                'parallel' => [
                    'id' => $parallel->id,
                    'paralelo' => $parallel->paralelo,
                    'turno' => $parallel->turno,
                ],
                'career' => $parallel->course?->career ? $parallel->course->career->only(['id', 'name', 'type']) : null,
                'columns_by_parcial' => $columnsByParcial->map(function ($cols) {
                    return $cols->map(fn($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'type' => $c->type,
                        'weight' => $c->weight,
                    ])->values();
                }),
                'students' => $reportData,
                'summary' => [
                    'total_estudiantes' => $totalStudents,
                    'aprobados' => $aprobados,
                    'reprobados' => $reprobados,
                    'promedio_general' => round($promedioGeneral, 2),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Listado general de calificaciones de un paralelo: matriz estudiantes x materias
     */
    public function generalByParallel(int $parallelId, Request $request)
    {
        try {
            $parallel = Parallel::with('course.career')->findOrFail($parallelId);
            $course = $parallel->course;
            $year = $request->input('year');

            $subjects = Subject::where('career_id', $course->career_id)
                ->where('level', $course->level)
                ->orderBy('name')
                ->get(['id', 'name', 'sigla']);

            $qualificationsQuery = Qualification::where('course_id', $course->id)
                ->where('parallel_id', $parallelId)
                ->whereIn('subject_id', $subjects->pluck('id'));

            if ($year) {
                $qualificationsQuery->whereYear('updated_at', $year);
            }

            $qualifications = $qualificationsQuery->get()
                ->keyBy(fn ($q) => $q->student_id . '_' . $q->subject_id);

            if ($year) {
                $studentIds = $qualifications->pluck('student_id')->unique();
            } else {
                $studentIds = StudentParallel::where('parallel_id', $parallelId)
                    ->where('status', true)
                    ->pluck('student_id');
            }

            $students = Student::whereIn('id', $studentIds)
                ->with('user')
                ->get();

            $rows = $students->map(function ($student) use ($subjects, $qualifications) {
                $grades = [];
                $sum = 0;
                $count = 0;

                foreach ($subjects as $subject) {
                    $qual = $qualifications->get($student->id . '_' . $subject->id);
                    $final = $qual?->final_grade;
                    $recoveryGrade = $qual?->recovery_grade;

                    $displayGrade = $final;
                    if ($recoveryGrade !== null && $final !== null && $final < 61) {
                        $displayGrade = $recoveryGrade;
                    }

                    $grades[$subject->id] = $displayGrade !== null ? round($displayGrade, 2) : null;
                    if ($displayGrade !== null) {
                        $sum += $displayGrade;
                        $count++;
                    }
                }

                return [
                    'id' => $student->id,
                    'name' => trim(($student->user->name ?? '') . ' ' . ($student->user->first_lastname ?? '') . ' ' . ($student->user->second_lastname ?? '')),
                    'ci' => $student->user->ci ?? '—',
                    'grades' => $grades,
                    'average' => $count > 0 ? round($sum / $count, 2) : null,
                ];
            });

            $appliedGrades = collect($rows->pluck('grades')->toArray());
            $allGrades = [];
            foreach ($appliedGrades as $studentGrades) {
                foreach ($studentGrades as $grade) {
                    if ($grade !== null) {
                        $allGrades[] = $grade;
                    }
                }
            }

            $approved = collect($allGrades)->filter(fn ($g) => $g >= 61)->count();
            $failed = collect($allGrades)->filter(fn ($g) => $g < 61)->count();

            return response()->json([
                'parallel' => $parallel,
                'course' => $course->only(['id', 'name', 'level']),
                'career' => $course->career ? $course->career->only(['id', 'name', 'type']) : null,
                'year' => $year ? (int) $year : null,
                'available_years' => Qualification::where('course_id', $course->id)
                    ->where('parallel_id', $parallelId)
                    ->selectRaw('YEAR(updated_at) as y')
                    ->distinct()
                    ->orderByDesc('y')
                    ->pluck('y'),
                'subjects' => $subjects,
                'students' => $rows,
                'summary' => [
                    'promedio_general' => count($allGrades) > 0 ? round(array_sum($allGrades) / count($allGrades), 2) : null,
                    'aprobados' => $approved,
                    'reprobados' => $failed,
                    'total_notas' => count($allGrades),
                    'total_estudiantes' => $rows->count(),
                    'total_materias' => $subjects->count(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Gestiones (años) con calificaciones registradas
     */
    public function years()
    {
        try {
            $years = Qualification::selectRaw('YEAR(updated_at) as y')
                ->distinct()
                ->orderByDesc('y')
                ->pluck('y');

            return response()->json([
                'years' => $years,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Exportar calificaciones de un paralelo y gestión a Excel.
     */
    public function exportCalificaciones(Request $request)
    {
        $request->validate([
            'parallel_id' => 'required|integer|exists:parallels,id',
            'year' => 'required|integer|min:1900|max:2100',
        ]);

        try {
            $service = new GradeExportService();
            $path = $service->generate((int) $request->parallel_id, (int) $request->year);

            $parallel = Parallel::with('course')->find($request->parallel_id);
            $filename = 'Calificaciones_' . ($parallel?->course?->name ?? 'curso')
                . '_' . ($parallel?->paralelo ?? '')
                . '_' . $request->year . '.xlsx';
            $filename = str_replace(' ', '_', $filename);

            return response()->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ])->deleteFileAfterSend(true);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Exportar informe de parciales a Excel
     */
    public function exportParcialReport(Request $request)
    {
        $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'parallel_id' => 'required|integer|exists:parallels,id',
        ]);

        try {
            $service = new \App\Services\ParcialReportExportService();
            $path = $service->generate((int) $request->subject_id, (int) $request->parallel_id);

            $subject = Subject::find($request->subject_id);
            $parallel = Parallel::with('course')->find($request->parallel_id);
            $filename = 'Informe_Parciales_' . ($subject?->sigla ?? 'materia')
                . '_' . ($parallel?->paralelo ?? '')
                . '.xlsx';
            $filename = str_replace(' ', '_', $filename);

            return response()->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ])->deleteFileAfterSend(true);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calcular promedio ponderado de un tipo específico (teorica/practica)
     */
    private function calculateTypeAverage($columns, $details, string $type): ?float
    {
        $sum = 0;
        $weightSum = 0;

        foreach ($columns as $col) {
            if ($col->type !== $type) continue;

            $detail = $details instanceof \Illuminate\Support\Collection
                ? $details->firstWhere('evaluation_column_id', $col->id)
                : collect($details)->firstWhere('evaluation_column_id', $col->id);

            if ($detail && $detail->grade !== null) {
                $sum += $detail->grade * $col->weight;
                $weightSum += $col->weight;
            }
        }

        return $weightSum > 0 ? $sum / $weightSum : null;
    }

    /**
     * Calcular nota final con ponderación teoría/práctica
     */
    private function calculateFinalGrade(?float $theoreticalAvg, ?float $practicalAvg, ?Subject $subject): ?float
    {
        if (!$subject) {
            if ($theoreticalAvg !== null && $practicalAvg !== null) {
                return ($theoreticalAvg + $practicalAvg) / 2;
            }
            return $theoreticalAvg ?? $practicalAvg;
        }

        if ($theoreticalAvg !== null && $practicalAvg !== null) {
            return ($theoreticalAvg * $subject->theory_weight) + ($practicalAvg * $subject->practice_weight);
        }

        if ($theoreticalAvg !== null) {
            return $theoreticalAvg;
        }

        if ($practicalAvg !== null) {
            return $practicalAvg;
        }

        return null;
    }
}
