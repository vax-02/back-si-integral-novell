<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\Subject;
use Illuminate\Http\Request;
use Exception;

class ScheduleController extends Controller
{
    /**
     * Obtener materias de una carrera filtradas por nivel
     */
    public function subjectsByCareer(Request $request, $careerId)
    {
        try {
            $query = Subject::where('career_id', $careerId);

            if ($request->filled('level')) {
                $query->where('level', $request->input('level'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('sigla', 'like', "%{$search}%");
                });
            }

            $subjects = $query->orderBy('sigla')->get(['id', 'sigla', 'name', 'level']);

            return response()->json([
                'subjects' => $subjects,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener horarios guardados de un paralelo
     */
    public function getByParallel($parallelId)
    {
        try {
            $schedules = Schedule::where('parallel_id', $parallelId)
                ->with('subject:id,sigla,name')
                ->get();

            return response()->json([
                'schedules' => $schedules,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Guardar horarios de un paralelo (reemplaza todos los existentes)
     */
    public function save(Request $request)
    {
        $request->validate([
            'parallel_id' => 'required|integer|exists:parallels,id',
            'schedules' => 'required|array',
            'schedules.*.day' => 'required|string|max:20',
            'schedules.*.start_time' => 'required|string',
            'schedules.*.end_time' => 'required|string',
            'schedules.*.subject_id' => 'nullable|integer|exists:subjects,id',
        ]);

        try {
            $parallelId = $request->input('parallel_id');
            
            // Eliminar horarios existentes
            Schedule::where('parallel_id', $parallelId)->delete();

            // Insertar nuevos horarios
            $schedulesData = [];
            foreach ($request->input('schedules') as $schedule) {
                $schedulesData[] = [
                    'parallel_id' => $parallelId,
                    'day' => $schedule['day'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                    'subject_id' => $schedule['subject_id'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($schedulesData)) {
                Schedule::insert($schedulesData);
            }

            return response()->json([
                'message' => 'Horario guardado correctamente.',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Crear un nuevo item de horario individual
     */
    public function store(Request $request)
    {
        $request->validate([
            'parallel_id' => 'required|integer|exists:parallels,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'day' => 'required|string|max:20',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
        ]);

        try {
            $schedule = Schedule::create([
                'parallel_id' => $request->parallel_id,
                'subject_id' => $request->subject_id,
                'day' => $request->day,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
            ]);

            $schedule->load('subject:id,sigla,name');

            return response()->json([
                'message' => 'Horario agregado correctamente.',
                'schedule' => $schedule,
            ], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar un item de horario individual
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'day' => 'required|string|max:20',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
        ]);

        try {
            $schedule = Schedule::findOrFail($id);
            $schedule->update([
                'subject_id' => $request->subject_id,
                'day' => $request->day,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
            ]);

            $schedule->load('subject:id,sigla,name');

            return response()->json([
                'message' => 'Horario actualizado correctamente.',
                'schedule' => $schedule,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar un item de horario
     */
    public function destroy($id)
    {
        try {
            $schedule = Schedule::findOrFail($id);
            $schedule->delete();

            return response()->json([
                'message' => 'Horario eliminado correctamente.',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}