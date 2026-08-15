<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentParallel;
use App\Models\Schedule;
use App\Models\DocenteSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentScheduleController extends Controller
{
    public function mySchedule()
    {
        try {
            $user = Auth::user();

            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'message' => 'No se encontró un estudiante asociado al usuario.',
                ], 404);
            }

            $studentParallels = StudentParallel::where('student_id', $student->id)
                ->where('status', 1)
                ->with([
                    'parallel.course.career',
                ])
                ->get();

            if ($studentParallels->isEmpty()) {
                return response()->json([
                    'message' => 'El estudiante no tiene paralelos asignados.',
                    'careers' => [],
                ]);
            }

            $careersData = [];

            foreach ($studentParallels as $sp) {
                $parallel = $sp->parallel;
                $course = $parallel->course;
                $career = $course->career;

                if (!$career) continue;

                $schedules = Schedule::where('parallel_id', $parallel->id)
                    ->with('subject')
                    ->orderBy('day')
                    ->orderBy('start_time')
                    ->get();

                $scheduleData = $schedules->map(function ($schedule) use ($parallel) {
                    // Buscar docente asignado a esta materia en este paralelo
                    $docenteAssignment = DocenteSubject::where('subject_id', $schedule->subject_id)
                        ->where('parallel_id', $parallel->id)
                        ->where('status', 1)
                        ->with('docente')
                        ->first();

                    $docente = $docenteAssignment?->docente;

                    return [
                        'day' => $schedule->day,
                        'start_time' => substr($schedule->start_time, 0, 5),
                        'end_time' => substr($schedule->end_time, 0, 5),
                        'subject_id' => $schedule->subject_id,
                        'subject' => [
                            'sigla' => $schedule->subject?->sigla,
                            'name' => $schedule->subject?->name,
                        ],
                        'docente' => $docente ? trim(
                            $docente->name . ' ' .
                            ($docente->first_lastname ?? '') . ' ' .
                            ($docente->second_lastname ?? '')
                        ) : null,
                    ];
                });

                // Buscar si ya tenemos esta carrera registrada
                $existingIndex = null;
                foreach ($careersData as $i => $c) {
                    if ($c['id'] === $career->id) {
                        $existingIndex = $i;
                        break;
                    }
                }

                $careerEntry = [
                    'id' => $career->id,
                    'name' => $career->name,
                    'course_name' => $course->name,
                    'course_level' => $course->level,
                    'turno' => $parallel->turno,
                    'paralelo' => $parallel->paralelo,
                    'schedules' => $scheduleData,
                ];

                if ($existingIndex !== null) {
                    $careersData[$existingIndex]['schedules'] = array_merge(
                        $careersData[$existingIndex]['schedules'],
                        $scheduleData->toArray()
                    );
                } else {
                    $careersData[] = $careerEntry;
                }
            }

            return response()->json([
                'careers' => $careersData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener el horario del estudiante.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}