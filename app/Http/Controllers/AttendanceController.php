<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Docente;
use App\Models\DocenteSchedule;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    private const DAYS = [
        0 => 'Domingo',
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miercoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sabado',
    ];

    private static array $DAY_MAP = [
        'Lunes'     => 'Lunes',
        'Martes'    => 'Martes',
        'Miércoles' => 'Miercoles',
        'Miercoles' => 'Miercoles',
        'Jueves'    => 'Jueves',
        'Viernes'   => 'Viernes',
        'Sábado'    => 'Sabado',
        'Sabado'    => 'Sabado',
        'Domingo'   => 'Domingo',
    ];

    private static function normalizeDay(?string $day): string
    {
        if ($day === null) return '';
        return self::$DAY_MAP[$day] ?? $day;
    }

    // ─────────────────────────────────────────────────────────────
    //  CONFIG (PIN + TOLERANCIA)  PUT /api/docentes/{docente}/attendance-config
    // ─────────────────────────────────────────────────────────────
    public function updateConfig(Request $request, Docente $docente)
    {
        $request->validate([
            'pin'     => ['nullable', 'string', 'max:20', 'unique:docentes,biometric_pin,' . $docente->id],
            'minutes' => ['required', 'integer', 'min:0', 'max:60'],
        ]);

        $docente->update([
            'biometric_pin'     => $request->filled('pin') ? $request->pin : null,
            'tolerance_minutes' => $request->minutes,
        ]);

        return response()->json([
            'message' => 'Configuración de asistencia guardada.',
            'docente' => $docente->only(['id', 'biometric_pin', 'tolerance_minutes']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  HORARIOS  GET /api/docentes/{docente}/schedules
    // ─────────────────────────────────────────────────────────────
    public function getSchedules(Docente $docente)
    {
        $schedules = $docente->schedules()
            ->orderByRaw("FIELD(day, 'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado','Domingo')")
            ->orderBy('entry_time')
            ->get();

        return response()->json([
            'docente'   => $docente->load('user:id,name,first_lastname,second_lastname')->only(['id', 'user', 'biometric_pin', 'tolerance_minutes']),
            'schedules' => $schedules,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  HORARIOS  POST /api/docentes/{docente}/schedules
    // ─────────────────────────────────────────────────────────────
    public function storeSchedule(Request $request, Docente $docente)
    {
        // Normalizar acentos: el frontend puede enviar "Miércoles" o "Sábado"
        $day = $this->normalizeDay($request->day);

        $request->validate([
            'day'        => ['required', 'in:' . implode(',', array_values(self::DAYS))],
            'entry_time' => ['required', 'date_format:H:i'],
        ]);

        $schedule = DocenteSchedule::create([
            'docente_id' => $docente->id,
            'day'        => $day,
            'entry_time' => $request->entry_time,
            'is_active'  => true,
        ]);

        return response()->json([
            'message'  => 'Horario agregado.',
            'schedule' => $schedule,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────
    //  HORARIOS  DELETE /api/docente-schedules/{schedule}
    // ─────────────────────────────────────────────────────────────
    public function destroySchedule(DocenteSchedule $schedule)
    {
        $schedule->delete();

        return response()->json(['message' => 'Horario eliminado.']);
    }

    // ─────────────────────────────────────────────────────────────
    //  IMPORTAR .dat  POST /api/attendance/import
    // ─────────────────────────────────────────────────────────────
    public function importAttendance(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file'],
        ]);

        try {
            $file = $request->file('file');
            $content = file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($content === false) {
                throw new Exception('No se pudo leer el archivo.');
            }

            $rows = [];
            $parsedPins = [];
            $invalid = 0;

            foreach ($content as $line) {
                $line = trim($line);
                if ($line === '') continue;

                $parts = explode("\t", $line);
                // Puede venir con espacios en vez de tabs: normalizar
                if (count($parts) < 2) {
                    $parts = preg_split('/\s+/', $line);
                }

                $pin = trim($parts[0] ?? '');
                $dateTime = trim($parts[1] ?? '');

                // Columna 3: indica si es ingreso. 1 = ingreso, ignorar resto.
                $isEntry = (int) trim($parts[2] ?? 0) === 1;

                $parsed = strtotime($dateTime);
                if ($pin === '' || $parsed === false || !$isEntry) {
                    $invalid++;
                    continue;
                }

                $key = $pin . '|' . date('Y-m-d H:i:s', $parsed);
                if (isset($rows[$key])) continue; // duplicado dentro del archivo

                $rows[$key] = [
                    'biometric_pin' => $pin,
                    'clock_at'      => date('Y-m-d H:i:s', $parsed),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
                $parsedPins[$pin] = true;
            }

            $pins = array_keys($parsedPins);
            $docenteMap = Docente::whereIn('biometric_pin', $pins)
                ->pluck('id', 'biometric_pin')
                ->toArray();

            foreach ($rows as &$row) {
                $row['docente_id'] = $docenteMap[$row['biometric_pin']] ?? null;
            }
            unset($row);

            $chunks = array_chunk($rows, 500);
            $before = AttendanceRecord::whereIn('biometric_pin', $pins)->count();

            DB::transaction(function () use ($chunks) {
                foreach ($chunks as $chunk) {
                    AttendanceRecord::insertOrIgnore($chunk);
                }
            });

            $after = AttendanceRecord::whereIn('biometric_pin', $pins)->count();
            $inserted = $after - $before;
            $skipped = count($rows) - $inserted;

            $unmapped = array_values(array_diff($pins, array_keys($docenteMap)));

            return response()->json([
                'message'  => 'Importación completada.',
                'summary'  => [
                    'total_lines' => count($content),
                    'valid'       => count($rows),
                    'invalid'     => $invalid,
                    'inserted'    => $inserted,
                    'skipped'     => $skipped,
                    'unmapped_pins' => $unmapped,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  VALIDAR  GET /api/attendance/validate?from&to&docente_id
    // ─────────────────────────────────────────────────────────────
    public function validateAttendance(Request $request)
    {
        $request->validate([
            'from'       => ['required', 'date_format:Y-m-d'],
            'to'         => ['required', 'date_format:Y-m-d'],
            'docente_id' => ['nullable', 'integer', 'exists:docentes,id'],
        ]);

        $from = Carbon::parse($request->from)->startOfDay();
        $to = Carbon::parse($request->to)->endOfDay();
        if ($from->gt($to)) {
            return response()->json(['error' => 'La fecha inicial no puede ser mayor que la final.'], 422);
        }

        $query = Docente::with('user:id,name,first_lastname,second_lastname,ci')
            ->with('schedules')
            ->whereHas('schedules');
        if ($request->docente_id) {
            $query->where('id', $request->docente_id);
        }

        $docentes = $query->get();

        $results = $docentes->map(function ($docente) use ($from, $to) {
            $schedules = $docente->schedules;
            $byDay = $schedules->groupBy('day')
                ->map(fn($items) => $items->pluck('entry_time')->sort()->values()->all());

            if ($byDay->isEmpty()) return null;

            $pin = $docente->biometric_pin;
            $records = $pin
                ? AttendanceRecord::where('biometric_pin', $pin)
                    ->whereBetween('clock_at', [$from, $to])
                    ->orderBy('clock_at')
                    ->get()
                : collect();

            $days = [];
            $weekly = [];
            $monthly = [];
            $totalLate = 0;
            $totalDays = 0;
            $totalMinutesLate = 0;

            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $dayName = self::DAYS[$cursor->dayOfWeek];
                $dateStr = $cursor->format('Y-m-d');
                $refs = $byDay->get($dayName);

                if ($refs) {
                    $totalDays++;
                    $dayRecords = $records
                        ->filter(fn($r) => $r->clock_at->format('Y-m-d') === $dateStr)
                        ->sortBy('clock_at')
                        ->values();

                    // Matching: cada ingreso se asigna al horario más cercano.
                    // Luego, cada horario que no tiene ingreso asignado es "falta".
                    $clockToRef = []; // idx_clock => idx_ref
                    $usedClocks = [];
                    foreach ($dayRecords as $ci => $r) {
                        $clockTime = strtotime($r->clock_at->format('H:i:s'));
                        $bestRef = null;
                        $bestDiff = PHP_INT_MAX;
                        foreach ($refs as $ri => $reference) {
                            $refTime = strtotime($reference);
                            $maxWindow = $refTime + (6 * 3600);
                            if ($clockTime < strtotime($reference) - ($docente->tolerance_minutes * 60)) continue;
                            if ($clockTime > $maxWindow) continue;
                            $diff = abs($clockTime - $refTime);
                            if ($diff < $bestDiff) {
                                $bestDiff = $diff;
                                $bestRef = $ri;
                            }
                        }
                        if ($bestRef !== null && !isset($clockToRef[$ci])) {
                            // Verificar que este ref no esté ya tomado por un ingreso más cercano
                            $alreadyAssigned = false;
                            foreach ($clockToRef as $assignedCi => $assignedRef) {
                                if ($assignedRef === $bestRef) {
                                    $oldClockTime = strtotime($dayRecords[$assignedCi]->clock_at->format('H:i:s'));
                                    if ($bestDiff < abs($oldClockTime - strtotime($refs[$bestRef]))) {
                                        unset($clockToRef[$assignedCi]);
                                    } else {
                                        $alreadyAssigned = true;
                                    }
                                    break;
                                }
                            }
                            if (!$alreadyAssigned) {
                                $clockToRef[$ci] = $bestRef;
                            }
                        }
                    }

                    $entries = [];
                    foreach ($refs as $ri => $reference) {
                        $allowed = Carbon::parse($reference)->addMinutes($docente->tolerance_minutes);
                        $clock = null;
                        $status = 'falta';
                        $minutesLate = null;

                        // Buscar si algún ingreso fue asignado a este horario
                        foreach ($clockToRef as $ci => $assignedRef) {
                            if ($assignedRef === $ri) {
                                $clock = $dayRecords[$ci]->clock_at->format('H:i:s');
                                if (strtotime($clock) <= strtotime($allowed->format('H:i:s'))) {
                                    $status = 'puntual';
                                } else {
                                    $status = 'retraso';
                                    $minutesLate = (int) ceil(
                                        (strtotime($clock) - strtotime($allowed->format('H:i:s'))) / 60
                                    );
                                    $totalLate++;
                                    $totalMinutesLate += $minutesLate;
                                }
                                break;
                            }
                        }

                        $entries[] = [
                            'reference_time' => $reference,
                            'first_clock'    => $clock,
                            'status'         => $status,
                            'minutes_late'   => $minutesLate,
                        ];
                    }

                    $days[] = [
                        'date'      => $dateStr,
                        'day'       => $dayName,
                        'tolerance' => $docente->tolerance_minutes,
                        'entries'   => $entries,
                    ];
                }
                $cursor->addDay();
            }

            // Agrupación semanal por rango de fechas (lunes a domingo)
            foreach ($days as $day) {
                $hasLate = collect($day['entries'])
                    ->contains(fn($e) => $e['status'] === 'retraso');
                $d = Carbon::parse($day['date']);
                $start = $d->copy()->startOfWeek();
                $end = $d->copy()->endOfWeek();
                $label = $start->format('d/m') . ' – ' . $end->format('d/m');
                if (!isset($weekly[$label])) {
                    $weekly[$label] = ['week_label' => $label, 'late_count' => 0, 'total_days' => 0];
                }
                $weekly[$label]['total_days']++;
                if ($hasLate) {
                    $weekly[$label]['late_count']++;
                }
            }

            // Agrupación mensual
            foreach ($days as $day) {
                $hasLate = collect($day['entries'])
                    ->contains(fn($e) => $e['status'] === 'retraso');
                $d = Carbon::parse($day['date']);
                $label = $d->format('F Y');
                $key = $d->format('Y-m');
                if (!isset($monthly[$key])) {
                    $monthly[$key] = ['month' => $key, 'month_label' => $label, 'late_count' => 0, 'total_days' => 0];
                }
                $monthly[$key]['total_days']++;
                if ($hasLate) {
                    $monthly[$key]['late_count']++;
                }
            }

            return [
                'id'              => $docente->id,
                'name'            => trim(($docente->user->name ?? '') . ' ' . ($docente->user->first_lastname ?? '') . ' ' . ($docente->user->second_lastname ?? '')),
                'ci'              => $docente->user->ci ?? '—',
                'biometric_pin'   => $pin ?? null,
                'tolerance'       => $docente->tolerance_minutes,
                'days'            => $days,
                'weekly'          => array_values($weekly),
                'monthly'         => array_values($monthly),
                'totals'          => [
                    'total_days'        => $totalDays,
                    'late_count'        => $totalLate,
                    'total_minutes_late'=> $totalMinutesLate,
                ],
            ];
        })->filter()->values();

        return response()->json(['docentes' => $results]);
    }
}
