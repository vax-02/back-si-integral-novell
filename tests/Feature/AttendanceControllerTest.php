<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Degree;
use App\Models\Docente;
use App\Models\DocenteSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Docente $docente;

    protected function setUp(): void
    {
        parent::setUp();

        $degree = Degree::create(['name' => 'Ingeniería de Sistemas', 'abbreviation' => 'IS']);

        $this->user = User::create([
            'ci'              => '1234567',
            'name'            => 'Juan',
            'first_lastname'  => 'Perez',
            'second_lastname' => 'Lopez',
            'email'           => 'juan@test.com',
            'password'        => bcrypt('password'),
        ]);

        $this->docente = Docente::create([
            'user_id'           => $this->user->id,
            'degree_id'         => $degree->id,
            'biometric_pin'     => '1234',
            'tolerance_minutes' => 15,
        ]);

        $this->actingAs($this->user, 'sanctum');
    }

    private function createSchedule(string $day, string $entryTime): DocenteSchedule
    {
        return DocenteSchedule::create([
            'docente_id'  => $this->docente->id,
            'day'         => $day,
            'entry_time'  => $entryTime,
            'is_active'   => true,
        ]);
    }

    private function createRecord(string $date, string $time): AttendanceRecord
    {
        return AttendanceRecord::create([
            'docente_id'    => $this->docente->id,
            'biometric_pin' => '1234',
            'clock_at'      => "$date $time",
        ]);
    }

    private function validate(string $from, string $to, ?int $docenteId = null): array
    {
        $params = "from=$from&to=$to";
        if ($docenteId) $params .= "&docente_id=$docenteId";

        $response = $this->withoutMiddleware()->getJson("/api/attendance/validate?$params");
        $response->assertOk();

        return $response->json('docentes');
    }

    private function getDayEntries(string $from, string $to): array
    {
        $docentes = $this->validate($from, $to);
        $this->assertNotEmpty($docentes, 'No se retornaron docentes');

        $docente = collect($docentes)->firstWhere('id', $this->docente->id);
        $this->assertNotNull($docente, 'No se encontró el docente');

        return $docente['days'];
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 1: Puntual - ingreso exacto a la hora
    // ═════════════════════════════════════════════════════════════
    public function test_puntual_when_clock_in_exactly_on_time(): void
    {
        // 2026-08-17 es Lunes
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '09:00:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $this->assertCount(1, $days);

        $entries = $days[0]['entries'];
        $this->assertCount(1, $entries);
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('09:00:00', $entries[0]['first_clock']);
        $this->assertNull($entries[0]['minutes_late']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 2: Puntual - ingreso dentro de la tolerancia
    // ═════════════════════════════════════════════════════════════
    public function test_puntual_when_clock_in_within_tolerance(): void
    {
        // 2026-08-17 es Lunes
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '09:14:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertEquals('puntual', $entries[0]['status']);
    }

    public function test_puntual_when_clock_in_exactly_at_tolerance_limit(): void
    {
        // tolerancia = 15 min → 09:15 es el límite exacto
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '09:15:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertEquals('puntual', $entries[0]['status']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 3: Retraso - ingreso después de la tolerancia
    // ═════════════════════════════════════════════════════════════
    public function test_retraso_when_clock_in_after_tolerance(): void
    {
        // 2026-08-18 es Martes
        $this->createSchedule('Martes', '09:00');
        $this->createRecord('2026-08-18', '09:16:00');

        $days = $this->getDayEntries('2026-08-18', '2026-08-18');
        $entries = $days[0]['entries'];

        $this->assertEquals('retraso', $entries[0]['status']);
        $this->assertEquals(1, $entries[0]['minutes_late']);
    }

    public function test_retraso_with_minutes_late_calculated_correctly(): void
    {
        // tolerancia = 15 min → 09:30 → 15 min de retraso
        $this->createSchedule('Martes', '09:00');
        $this->createRecord('2026-08-18', '09:30:00');

        $days = $this->getDayEntries('2026-08-18', '2026-08-18');
        $entries = $days[0]['entries'];

        $this->assertEquals('retraso', $entries[0]['status']);
        $this->assertEquals(15, $entries[0]['minutes_late']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 4: Falta - sin marcación
    // ═════════════════════════════════════════════════════════════
    public function test_falta_when_no_clock_in(): void
    {
        // 2026-08-20 es Jueves
        $this->createSchedule('Jueves', '09:00');

        $days = $this->getDayEntries('2026-08-20', '2026-08-20');
        $entries = $days[0]['entries'];

        $this->assertEquals('falta', $entries[0]['status']);
        $this->assertNull($entries[0]['first_clock']);
        $this->assertNull($entries[0]['minutes_late']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 5: BUG FIX - Ingreso a las 18:00 para horario 09:00
    //           NO debe ser puntual (era el bug principal)
    // ═════════════════════════════════════════════════════════════
    public function test_18_00_clock_in_for_09_00_schedule_must_not_be_puntual(): void
    {
        // 2026-08-17 es Lunes
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '18:00:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        // 18:00 excede ventana de 6h → no empareja → falta
        $this->assertEquals('falta', $entries[0]['status']);
        $this->assertNull($entries[0]['first_clock']);
    }

    public function test_20_00_clock_in_for_09_00_schedule_must_be_falta(): void
    {
        // 2026-08-17 es Lunes
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '20:00:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertEquals('falta', $entries[0]['status']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 6: Dos horarios en el mismo día
    // ═════════════════════════════════════════════════════════════
    public function test_two_schedules_same_day_validate_independently(): void
    {
        $this->createSchedule('Lunes', '09:00');
        $this->createSchedule('Lunes', '14:00');

        $this->createRecord('2026-08-17', '08:55:00');
        $this->createRecord('2026-08-17', '14:30:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertCount(2, $entries);

        $this->assertEquals('09:00', substr($entries[0]['reference_time'], 0, 5));
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('08:55:00', $entries[0]['first_clock']);

        $this->assertEquals('14:00', substr($entries[1]['reference_time'], 0, 5));
        $this->assertEquals('retraso', $entries[1]['status']);
        $this->assertEquals(15, $entries[1]['minutes_late']);
    }

    public function test_two_schedules_first_falta_second_puntual(): void
    {
        $this->createSchedule('Lunes', '09:00');
        $this->createSchedule('Lunes', '14:00');

        // Solo marcó a las 14:00
        $this->createRecord('2026-08-17', '14:00:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertCount(2, $entries);
        $this->assertEquals('falta', $entries[0]['status']);
        $this->assertEquals('puntual', $entries[1]['status']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 7: Ingreso demasiado temprano
    // ═════════════════════════════════════════════════════════════
    public function test_clock_in_too_early_is_falta(): void
    {
        // 2026-08-17 es Lunes, tolerancia = 15 min → ventana inferior = 08:45
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '07:00:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertEquals('falta', $entries[0]['status']);
    }

    public function test_clock_in_just_before_tolerance_is_puntual(): void
    {
        // 2026-08-17 es Lunes, 08:45 = límite inferior
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '08:45:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertEquals('puntual', $entries[0]['status']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 8: Días sin acentos coinciden con DB
    // ═════════════════════════════════════════════════════════════
    public function test_schedule_order_uses_non_accented_day_names(): void
    {
        // 2026-08-19 es Miércoles
        $this->createSchedule('Miercoles', '10:00');
        $this->createRecord('2026-08-19', '10:00:00');

        $days = $this->getDayEntries('2026-08-19', '2026-08-19');
        $this->assertCount(1, $days);
        $this->assertEquals('Miercoles', $days[0]['day']);
        $this->assertEquals('puntual', $days[0]['entries'][0]['status']);
    }

    public function test_schedule_saturday_uses_non_accented_day(): void
    {
        // 2026-08-22 es Sábado
        $this->createSchedule('Sabado', '08:00');
        $this->createRecord('2026-08-22', '08:00:00');

        $days = $this->getDayEntries('2026-08-22', '2026-08-22');
        $this->assertCount(1, $days);
        $this->assertEquals('Sabado', $days[0]['day']);
        $this->assertEquals('puntual', $days[0]['entries'][0]['status']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 9: Rango de fechas
    // ═════════════════════════════════════════════════════════════
    public function test_range_of_dates_returns_multiple_days(): void
    {
        $this->createSchedule('Lunes', '09:00');
        $this->createSchedule('Martes', '09:00');
        $this->createSchedule('Miercoles', '09:00');
        $this->createSchedule('Jueves', '09:00');
        $this->createSchedule('Viernes', '09:00');

        // 2026-08-17 Lunes, 2026-08-21 Viernes
        $this->createRecord('2026-08-17', '09:00:00'); // Lunes: puntual
        $this->createRecord('2026-08-18', '09:20:00'); // Martes: retraso 5min (09:20 > 09:15)
        // Miercoles: sin marca
        $this->createRecord('2026-08-20', '09:30:00'); // Jueves: retraso 15min
        // Viernes: sin marca

        $days = $this->getDayEntries('2026-08-17', '2026-08-21');

        $this->assertCount(5, $days);

        $this->assertEquals('puntual', $days[0]['entries'][0]['status']);
        $this->assertEquals('retraso', $days[1]['entries'][0]['status']);
        $this->assertEquals('falta', $days[2]['entries'][0]['status']);
        $this->assertEquals('retraso', $days[3]['entries'][0]['status']);
        $this->assertEquals('falta', $days[4]['entries'][0]['status']);

        // Verificar totales
        $docenteResult = collect($this->validate('2026-08-17', '2026-08-21'))
            ->firstWhere('id', $this->docente->id);

        $this->assertEquals(5, $docenteResult['totals']['total_days']);
        $this->assertEquals(2, $docenteResult['totals']['late_count']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 10: tolerance_minutes configurable
    // ═════════════════════════════════════════════════════════════
    public function test_different_tolerance_values(): void
    {
        // Tol 30 min → 09:29 es puntual
        $this->docente->update(['tolerance_minutes' => 30]);
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '09:29:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $this->assertEquals('puntual', $days[0]['entries'][0]['status']);

        // Tol 5 min → 09:29 es retraso 24min
        $this->docente->update(['tolerance_minutes' => 5]);
        $days2 = $this->getDayEntries('2026-08-17', '2026-08-17');
        $this->assertEquals('retraso', $days2[0]['entries'][0]['status']);
        $this->assertEquals(24, $days2[0]['entries'][0]['minutes_late']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 11: Ventana de 6 horas
    // ═════════════════════════════════════════════════════════════
    public function test_clock_in_exactly_at_6h_window_limit(): void
    {
        // 2026-08-17 es Lunes, 09:00 + 6h = 15:00 → dentro de ventana pero retraso
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '15:00:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertEquals('retraso', $entries[0]['status']);
        $this->assertEquals(345, $entries[0]['minutes_late']);
    }

    public function test_clock_in_beyond_6h_window_is_falta(): void
    {
        // 2026-08-17 es Lunes, 09:00 + 6h + 1min = 15:01 → fuera de ventana
        $this->createSchedule('Lunes', '09:00');
        $this->createRecord('2026-08-17', '15:01:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertEquals('falta', $entries[0]['status']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 12: Múltiples ingresos, usa el más cercano dentro de ventana
    // ═════════════════════════════════════════════════════════════
    public function test_multiple_clock_ins_uses_closest_within_window(): void
    {
        $this->createSchedule('Lunes', '09:00');

        // 08:30 → fuera de ventana (tol=15, min=08:45), 09:10 y 09:20 dentro
        $this->createRecord('2026-08-17', '08:30:00');
        $this->createRecord('2026-08-17', '09:10:00');
        $this->createRecord('2026-08-17', '09:20:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        // Debe usar 09:10 (el más cercano dentro de ventana)
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('09:10:00', $entries[0]['first_clock']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 13: Sin schedule → no aparece en respuesta
    // ═════════════════════════════════════════════════════════════
    public function test_docente_without_schedules_not_returned(): void
    {
        // Crear al menos un horario para nuestro docente principal
        $this->createSchedule('Lunes', '09:00');

        $degree = Degree::create(['name' => 'Medicina', 'abbreviation' => 'MED']);
        $user2 = User::create([
            'ci'              => '9999999',
            'name'            => 'Ana',
            'first_lastname'  => 'Garcia',
            'second_lastname' => null,
            'email'           => 'ana@test.com',
            'password'        => bcrypt('password'),
        ]);
        Docente::create([
            'user_id'           => $user2->id,
            'degree_id'         => $degree->id,
            'biometric_pin'     => '5678',
            'tolerance_minutes' => 10,
        ]);

        $docentes = $this->validate('2026-08-17', '2026-08-17');
        // Docente2 sin schedules no aparece, solo nuestro docente con schedule Lunes
        $this->assertCount(1, $docentes);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 14: Múltiples ingresos con segundos de diferencia
    //           Debe tomar el más temprano
    // ═════════════════════════════════════════════════════════════
    public function test_multiple_clock_ins_within_seconds_uses_earliest(): void
    {
        $this->createSchedule('Lunes', '09:00');

        // Dos ingresos con 45 segundos de diferencia
        $this->createRecord('2026-08-17', '08:58:30');
        $this->createRecord('2026-08-17', '08:59:15');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        // Debe usar el más temprano: 08:58:30
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('08:58:30', $entries[0]['first_clock']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 15: Múltiples ingresos con minutos de diferencia
    //           Donde el más temprano es puntual y el más tardío retraso
    // ═════════════════════════════════════════════════════════════
    public function test_multiple_clock_ins_uses_earliest_even_if_later_is_retraso(): void
    {
        // tolerancia = 15 min → límite = 09:15
        $this->createSchedule('Martes', '09:00');

        // 09:12 = puntual (antes de 09:15), 09:18 = retraso
        $this->createRecord('2026-08-18', '09:12:00');
        $this->createRecord('2026-08-18', '09:18:00');

        $days = $this->getDayEntries('2026-08-18', '2026-08-18');
        $entries = $days[0]['entries'];

        // Debe usar 09:12 (el más temprano) → puntual
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('09:12:00', $entries[0]['first_clock']);
        $this->assertNull($entries[0]['minutes_late']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 16: Tres ingresos, el más temprano es el correcto
    // ═════════════════════════════════════════════════════════════
    public function test_three_clock_ins_picks_earliest_within_window(): void
    {
        $this->createSchedule('Miercoles', '09:00');

        $this->createRecord('2026-08-19', '08:50:00'); // muy temprano (fuera de ventana, tol=15 → min=08:45)
        $this->createRecord('2026-08-19', '08:55:00'); // dentro de ventana, puntual
        $this->createRecord('2026-08-19', '09:10:00'); // dentro de ventana, puntual

        $days = $this->getDayEntries('2026-08-19', '2026-08-19');
        $entries = $days[0]['entries'];

        // 08:50 está dentro de ventana (08:45 es el mínimo), es el más temprano válido
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('08:50:00', $entries[0]['first_clock']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 17: Dos horarios, múltiples ingresos, cada uno usa el más temprano
    // ═════════════════════════════════════════════════════════════
    public function test_two_schedules_multiple_clock_ins_each_uses_earliest(): void
    {
        $this->createSchedule('Jueves', '09:00');
        $this->createSchedule('Jueves', '14:00');

        // Para las 09:00: dos ingresos cercanos
        $this->createRecord('2026-08-20', '08:57:00');
        $this->createRecord('2026-08-20', '08:58:30');

        // Para las 14:00: dos ingresos cercanos
        $this->createRecord('2026-08-20', '13:55:00');
        $this->createRecord('2026-08-20', '13:56:20');

        $days = $this->getDayEntries('2026-08-20', '2026-08-20');
        $entries = $days[0]['entries'];

        $this->assertCount(2, $entries);

        // Horario 09:00 → usa 08:57:00 (el más temprano)
        $this->assertEquals('09:00', substr($entries[0]['reference_time'], 0, 5));
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('08:57:00', $entries[0]['first_clock']);

        // Horario 14:00 → usa 13:55:00 (el más temprano)
        $this->assertEquals('14:00', substr($entries[1]['reference_time'], 0, 5));
        $this->assertEquals('puntual', $entries[1]['status']);
        $this->assertEquals('13:55:00', $entries[1]['first_clock']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 18: Ingreso temprano para un horario, normal para otro
    // ═════════════════════════════════════════════════════════════
    public function test_early_clock_in_for_first_schedule_does_not_steal_from_second(): void
    {
        $this->createSchedule('Viernes', '09:00');
        $this->createSchedule('Viernes', '14:00');

        // 08:50 → válido solo para 09:00 (min window = 08:45)
        // 14:05 → válido solo para 14:00
        $this->createRecord('2026-08-21', '08:50:00');
        $this->createRecord('2026-08-21', '14:05:00');

        $days = $this->getDayEntries('2026-08-21', '2026-08-21');
        $entries = $days[0]['entries'];

        $this->assertCount(2, $entries);
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('08:50:00', $entries[0]['first_clock']);
        $this->assertEquals('puntual', $entries[1]['status']);
        $this->assertEquals('14:05:00', $entries[1]['first_clock']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 19: Un solo ingreso para dos horarios → se asigna al primero
    // ═════════════════════════════════════════════════════════════
    public function test_single_clock_in_for_two_schedules_assigns_to_first(): void
    {
        $this->createSchedule('Lunes', '09:00');
        $this->createSchedule('Lunes', '14:00');

        // Un ingreso a las 09:05 → válido para ambas referencias
        // Se asigna a la primera (09:00), la segunda queda como falta
        $this->createRecord('2026-08-17', '09:05:00');

        $days = $this->getDayEntries('2026-08-17', '2026-08-17');
        $entries = $days[0]['entries'];

        $this->assertCount(2, $entries);
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('09:05:00', $entries[0]['first_clock']);
        $this->assertEquals('falta', $entries[1]['status']);
        $this->assertNull($entries[1]['first_clock']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 20: Múltiples ingresos, todos fuera de ventana → falta
    // ═════════════════════════════════════════════════════════════
    public function test_multiple_clock_ins_all_outside_window_is_falta(): void
    {
        // tol=15, ref=09:00 → ventana [08:45, 15:00]
        $this->createSchedule('Martes', '09:00');

        // 07:00 → demasiado temprano (fuera de ventana inferior)
        // 16:00 → demasiado tarde (fuera de ventana superior de 6h)
        $this->createRecord('2026-08-18', '07:00:00');
        $this->createRecord('2026-08-18', '16:00:00');

        $days = $this->getDayEntries('2026-08-18', '2026-08-18');
        $entries = $days[0]['entries'];

        $this->assertEquals('falta', $entries[0]['status']);
        $this->assertNull($entries[0]['first_clock']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 21: Ingreso exacto en el límite de tolerancia + otros
    // ═════════════════════════════════════════════════════════════
    public function test_clock_in_at_tolerance_boundary_with_others(): void
    {
        // tol=15, ref=09:00 → límite = 09:15:00
        $this->createSchedule('Miercoles', '09:00');

        $this->createRecord('2026-08-19', '09:15:00'); // exacto en el límite
        $this->createRecord('2026-08-19', '09:14:59'); // un segundo antes
        $this->createRecord('2026-08-19', '09:16:01'); // un minuto después

        $days = $this->getDayEntries('2026-08-19', '2026-08-19');
        $entries = $days[0]['entries'];

        // El más temprano dentro de ventana es 09:14:59 → puntual
        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('09:14:59', $entries[0]['first_clock']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 22: Cinco ingresos en un día, uno solo válido
    // ═════════════════════════════════════════════════════════════
    public function test_five_clock_ins_only_one_valid(): void
    {
        $this->createSchedule('Jueves', '09:00');

        $this->createRecord('2026-08-20', '06:00:00'); // fuera ventana inferior
        $this->createRecord('2026-08-20', '07:30:00'); // fuera ventana inferior
        $this->createRecord('2026-08-20', '09:05:00'); // VÁLIDO → puntual
        $this->createRecord('2026-08-20', '15:30:00'); // fuera ventana (6h)
        $this->createRecord('2026-08-20', '20:00:00'); // fuera ventana

        $days = $this->getDayEntries('2026-08-20', '2026-08-20');
        $entries = $days[0]['entries'];

        $this->assertEquals('puntual', $entries[0]['status']);
        $this->assertEquals('09:05:00', $entries[0]['first_clock']);
    }

    // ═════════════════════════════════════════════════════════════
    //  TEST 23: Los segundos no se redondean hacia arriba
    //           61 seg de retraso = 1 min, no 2 min
    // ═════════════════════════════════════════════════════════════
    public function test_seconds_are_not_rounded_up_for_delay(): void
    {
        // tol=15, ref=09:00 → límite = 09:15:00
        $this->createSchedule('Viernes', '09:00');

        // 09:16:01 = 61 segundos de retraso → debe ser 1 min, no 2
        $this->createRecord('2026-08-21', '09:16:01');

        $days = $this->getDayEntries('2026-08-21', '2026-08-21');
        $entries = $days[0]['entries'];

        $this->assertEquals('retraso', $entries[0]['status']);
        $this->assertEquals(1, $entries[0]['minutes_late']);
    }

    public function test_119_seconds_is_1_minute_not_2(): void
    {
        // tol=15, ref=09:00 → límite = 09:15:00
        $this->createSchedule('Viernes', '09:00');

        // 09:16:59 = 119 seg = 1 min 59 seg → debe ser 1 min
        $this->createRecord('2026-08-21', '09:16:59');

        $days = $this->getDayEntries('2026-08-21', '2026-08-21');
        $entries = $days[0]['entries'];

        $this->assertEquals('retraso', $entries[0]['status']);
        $this->assertEquals(1, $entries[0]['minutes_late']);
    }

    public function test_120_seconds_is_2_minutes(): void
    {
        // tol=15, ref=09:00 → límite = 09:15:00
        $this->createSchedule('Viernes', '09:00');

        // 09:17:00 = 120 seg = 2 min exactos → debe ser 2 min
        $this->createRecord('2026-08-21', '09:17:00');

        $days = $this->getDayEntries('2026-08-21', '2026-08-21');
        $entries = $days[0]['entries'];

        $this->assertEquals('retraso', $entries[0]['status']);
        $this->assertEquals(2, $entries[0]['minutes_late']);
    }
}
