<?php

namespace App\Http\Controllers;

use App\Models\Career;
use App\Models\Course;
use App\Models\Docente;
use App\Models\Parallel;
use App\Models\Pay;
use App\Models\Student;
use App\Models\Subject;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Obtener datos agregados para el dashboard del home.
     */
    public function index()
    {
        try {
            $now = Carbon::now();
            $startOfYear = $now->copy()->startOfYear();

            $monthlyIncome = Pay::where('status', 1)
                ->where(
                    'created_at',
                    '>=',
                    $startOfYear->format('Y-m-d H:i:s')
                )
                ->selectRaw("DATE_FORMAT(`created_at`, '%Y-%m') AS `year_month`, SUM(`amount`) AS total")
                ->groupBy(DB::raw("DATE_FORMAT(`created_at`, '%Y-%m')"))
                ->orderBy('year_month', 'asc')
                ->get()
                ->keyBy('year_month');

            // Generar los meses desde Enero del año actual hasta el mes actual
            $months = [];
            $monthNames = [
                '01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr',
                '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
                '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic',
            ];

            for ($i = 0; $i < $now->month; $i++) {
                $date = $startOfYear->copy()->addMonths($i);
                $key = $date->format('Y-m');
                $monthNum = $date->format('m');
                $months[] = [
                    'month' => $monthNames[$monthNum],
                    'total' => (float) ($monthlyIncome[$key]->total ?? 0),
                ];
            }

            // ──────────────────────────────────────────────
            // 2. Mensualidades (pays) - Hoy, Esta semana, Este mes
            // ──────────────────────────────────────────────
            $todayPays = Pay::where('status', 1)
                ->whereDate('created_at', $now->toDateString())
                ->count();

            $weekStart = $now->copy()->startOfWeek();
            $weekPays = Pay::where('status', 1)
                ->where('created_at', '>=', $weekStart)
                ->count();

            $monthPays = Pay::where('status', 1)
                ->whereYear('created_at', $now->year)
                ->whereMonth('created_at', $now->month)
                ->count();

            // ──────────────────────────────────────────────
            // 5. KPIs generales
            // ──────────────────────────────────────────────
            $totalStudents = Student::count();
            $totalDocentes = Docente::count();
            $totalCareers = Career::count();
            $totalSubjects = Subject::count();
            $totalParallels = Parallel::count();

            return response()->json([
                'kpis' => [
                    'total_students' => $totalStudents,
                    'total_docentes' => $totalDocentes,
                    'total_careers' => $totalCareers,
                    'total_subjects' => $totalSubjects,
                    'total_parallels' => $totalParallels,
                ],
                'monthly_income' => $months,
                'mensualidades_pays' => [
                    'today' => $todayPays,
                    'this_week' => $weekPays,
                    'this_month' => $monthPays,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error al obtener datos del dashboard',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}