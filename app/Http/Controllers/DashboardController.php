<?php

namespace App\Http\Controllers;

use App\Models\Career;
use App\Models\Course;
use App\Models\Docente;
use App\Models\Institution;
use App\Models\Parallel;
use App\Models\Pay;
use App\Models\Qualification;
use App\Models\Student;
use App\Models\StudentCareer;
use App\Models\StudentParallel;
use App\Models\Subject;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Nota mínima de aprobación */
    private const PASSING_GRADE = 51;

    public function index()
    {
        try {
            $now = Carbon::now();
            $startOfYear = $now->copy()->startOfYear();
            $gestion = $now->year;

            $institution = Institution::first();

            // ──────────────────────────────────────────────
            // Ingresos por mes (neto) desde Enero hasta el mes actual
            // ──────────────────────────────────────────────
            $monthlyIncome = Pay::where('status', 1)
                ->where('created_at', '>=', $startOfYear->format('Y-m-d H:i:s'))
                ->selectRaw("DATE_FORMAT(`created_at`, '%Y-%m') AS `year_month`, SUM(`amount` - `discount`) AS total")
                ->groupBy(DB::raw("DATE_FORMAT(`created_at`, '%Y-%m')"))
                ->orderBy('year_month', 'asc')
                ->pluck('total', 'year_month');

            $monthNames = [
                '01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr',
                '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
                '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic',
            ];

            $months = [];
            for ($i = 0; $i < $now->month; $i++) {
                $key = $startOfYear->copy()->addMonths($i)->format('Y-m');
                $months[] = [
                    'month' => $monthNames[$startOfYear->copy()->addMonths($i)->format('m')],
                    'total' => (float) ($monthlyIncome[$key] ?? 0),
                ];
            }

            // ──────────────────────────────────────────────
            // KPIs generales
            // ──────────────────────────────────────────────
            $totalStudents = Student::whereHas('user', function ($q) {
                $q->where('status', 1);
            })->count();

            $assignedStudents = StudentParallel::where('status', true)->count();

            $totalDocentes = Docente::whereHas('user', function ($q) {
                $q->where('status', 1);
            })->count();

            $totalCareers = Career::where('status', 1)->count();
            $totalSubjects = Subject::count();
            $totalParallels = Parallel::where('status', 1)->count();
            $totalCapacity = Parallel::where('status', 1)->sum('limit');

            $occupancyRate = $totalCapacity > 0
                ? round($assignedStudents / $totalCapacity * 100, 1)
                : 0;

            // ──────────────────────────────────────────────
            // Ingresos (neto) y conteo de pagos
            // ──────────────────────────────────────────────
            $paysQuery = Pay::where('status', 1);
            $incomeToday = (clone $paysQuery)->whereDate('created_at', $now->toDateString())->sum(DB::raw('amount - discount'));
            $incomeMonth = (clone $paysQuery)->whereYear('created_at', $now->year)->whereMonth('created_at', $now->month)->sum(DB::raw('amount - discount'));
            $incomeYear = (clone $paysQuery)->whereYear('created_at', $now->year)->sum(DB::raw('amount - discount'));

            $paysToday = (clone $paysQuery)->whereDate('created_at', $now->toDateString())->count();
            $weekStart = $now->copy()->startOfWeek();
            $paysWeek = (clone $paysQuery)->where('created_at', '>=', $weekStart)->count();
            $paysMonth = (clone $paysQuery)->whereYear('created_at', $now->year)->whereMonth('created_at', $now->month)->count();
            $paysYear = (clone $paysQuery)->whereYear('created_at', $now->year)->count();

            // ──────────────────────────────────────────────
            // Ingresos por carrera (neto, año actual)
            // ──────────────────────────────────────────────
            $incomeByCareer = Pay::where('pays.status', 1)
                ->whereYear('pays.created_at', $now->year)
                ->join('concepts', 'concepts.id', '=', 'pays.concept_id')
                ->join('careers', 'careers.id', '=', 'concepts.career_id')
                ->select('careers.name as career', DB::raw('SUM(pays.amount - pays.discount) as total'))
                ->groupBy('careers.id', 'careers.name')
                ->orderByDesc('total')
                ->limit(6)
                ->get()
                ->map(fn($row) => ['name' => $row->career, 'total' => (float) $row->total])
                ->values();

            // ──────────────────────────────────────────────
            // Ingresos por concepto (neto, año actual)
            // ──────────────────────────────────────────────
            $incomeByConcept = Pay::where('pays.status', 1)
                ->whereYear('pays.created_at', $now->year)
                ->join('concepts', 'concepts.id', '=', 'pays.concept_id')
                ->select('concepts.type as type', 'concepts.description as description', DB::raw('SUM(pays.amount - pays.discount) as total'))
                ->groupBy('concepts.id', 'concepts.type', 'concepts.description')
                ->orderByDesc('total')
                ->limit(6)
                ->get()
                ->map(fn($row) => [
                    'name'  => trim($row->description ?? '') !== '' ? $row->description : $row->type,
                    'total' => (float) $row->total,
                ])
                ->values();

            // ──────────────────────────────────────────────
            // Estudiantes (matrícula) por carrera
            // ──────────────────────────────────────────────
            $studentsByCareer = StudentCareer::join('careers', 'careers.id', '=', 'student_careers.career_id')
                ->select(
                    'careers.name as career',
                    DB::raw("SUM(CASE WHEN student_careers.status = 'Activo' THEN 1 ELSE 0 END) as active"),
                    DB::raw("SUM(CASE WHEN student_careers.status = 'Retirado' OR student_careers.status = 'Suspendido' THEN 1 ELSE 0 END) as withdrawn")
                )
                ->groupBy('careers.id', 'careers.name')
                ->orderByDesc('active')
                ->get()
                ->map(fn($row) => [
                    'name' => $row->career,
                    'active' => (int) $row->active,
                    'withdrawn' => (int) $row->withdrawn,
                ])
                ->values();

            // ──────────────────────────────────────────────
            // Estudiantes por nivel de curso (pirámide de matrícula)
            // ──────────────────────────────────────────────
            $studentsByLevel = StudentParallel::where('student_parallels.status', true)
                ->join('parallels', 'parallels.id', '=', 'student_parallels.parallel_id')
                ->join('courses', 'courses.id', '=', 'parallels.course_id')
                ->select('courses.name as course_name', 'courses.level as level', DB::raw('COUNT(*) as count'))
                ->groupBy('courses.id', 'courses.name', 'courses.level')
                ->orderBy('level', 'asc')
                ->get()
                ->map(fn($row) => ['course_name' => $row->course_name, 'count' => (int) $row->count])
                ->values();

            // ──────────────────────────────────────────────
            // Materias con mayor reprobación (calificaciones publicadas)
            // ──────────────────────────────────────────────
            $subjectFailure = Qualification::where('published', true)
                ->whereNotNull('final_grade')
                ->with('subject:id,name,sigla')
                ->get(['subject_id', 'final_grade'])
                ->groupBy('subject_id')
                ->map(function ($qualifications) {
                    $total = $qualifications->count();
                    $failed = $qualifications->filter(fn($q) => $q->final_grade < self::PASSING_GRADE)->count();
                    $subject = $qualifications->first()->subject;
                    return [
                        'name'           => $subject ? $subject->name : '—',
                        'sigla'          => $subject ? $subject->sigla : '',
                        'total_students' => $total,
                        'failed_students'=> $failed,
                        'failure_rate'   => $total > 0 ? round($failed / $total * 100, 1) : 0,
                    ];
                })
                ->sortByDesc('failure_rate')
                ->take(5)
                ->values();

            // ──────────────────────────────────────────────
            // Actividad reciente de pagos
            // ──────────────────────────────────────────────
            $recentPayActivity = Pay::where('status', 1)
                ->with(['student.user:id,name,first_lastname,second_lastname', 'concept:id,description,type'])
                ->latest()
                ->limit(8)
                ->get()
                ->map(function ($pay) {
                    $student = $pay->student?->user;
                    return [
                        'student' => $student ? trim(($student->name ?? '') . ' ' . ($student->first_lastname ?? '') . ' ' . ($student->second_lastname ?? '')) : '—',
                        'concept' => $pay->concept ? ($pay->concept->description ?: $pay->concept->type) : '—',
                        'amount'  => (float) ($pay->amount - $pay->discount),
                        'time'    => $pay->created_at ? $pay->created_at->locale('es')->diffForHumans() : '',
                    ];
                })
                ->values();

            // ──────────────────────────────────────────────
            // Paralelos con mayor ocupación
            // ──────────────────────────────────────────────
            $busiestParallels = Parallel::where('status', 1)
                ->with(['course:id,name,career_id', 'course.career:id,name'])
                ->withCount(['students as students_count' => function ($query) {
                    $query->where('status', true);
                }])
                ->get()
                ->map(function ($parallel) {
                    $occupancy = $parallel->limit > 0
                        ? round($parallel->students_count / $parallel->limit * 100, 1)
                        : 0;
                    return [
                        'paralelo'       => $parallel->paralelo,
                        'turno'          => $parallel->turno,
                        'course_name'    => $parallel->course ? $parallel->course->name : '—',
                        'career_name'    => $parallel->course && $parallel->course->career ? $parallel->course->career->name : '—',
                        'students_count' => (int) $parallel->students_count,
                        'limit'          => (int) $parallel->limit,
                        'occupancy'      => $occupancy,
                    ];
                })
                ->sortByDesc('occupancy')
                ->take(6)
                ->values();

            return response()->json([
                'institution' => $institution ? [
                    'name'      => $institution->name,
                    'address'   => $institution->address,
                    'cellphone' => $institution->cellphone,
                    'email'     => $institution->email,
                ] : null,
                'gestion' => $gestion,
                'kpis' => [
                    'total_students'    => $totalStudents,
                    'assigned_students' => $assignedStudents,
                    'total_docentes'    => $totalDocentes,
                    'total_careers'     => $totalCareers,
                    'total_subjects'    => $totalSubjects,
                    'total_parallels'   => $totalParallels,
                    'total_capacity'    => $totalCapacity,
                    'occupancy_rate'    => $occupancyRate,
                    'income_year'       => (float) $incomeYear,
                    'income_month'      => (float) $incomeMonth,
                    'income_today'      => (float) $incomeToday,
                    'pays_today'        => $paysToday,
                    'pays_week'         => $paysWeek,
                    'pays_month'        => $paysMonth,
                    'pays_year'         => $paysYear,
                ],
                'monthly_income'     => $months,
                'income_by_career'   => $incomeByCareer,
                'income_by_concept'  => $incomeByConcept,
                'students_by_career' => $studentsByCareer,
                'students_by_level'  => $studentsByLevel,
                'subject_failure'    => $subjectFailure,
                'recent_pay_activity' => $recentPayActivity,
                'busiest_parallels'  => $busiestParallels,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al obtener datos del dashboard',
            ], 500);
        }
    }
}
