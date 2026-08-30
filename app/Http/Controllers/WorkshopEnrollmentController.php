<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\User;
use App\Models\UserRoles;
use App\Models\WorkshopEnrollment;
use App\Models\WorkshopEdition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WorkshopEnrollmentController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = WorkshopEnrollment::with([
                'student.user',
                'edition.workshop',
            ]);

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->whereHas('student.user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('first_lastname', 'like', "%{$search}%")
                      ->orWhere('second_lastname', 'like', "%{$search}%")
                      ->orWhere('ci', 'like', "%{$search}%");
                });
            }

            if ($request->filled('edition_id')) {
                $query->where('workshop_edition_id', $request->input('edition_id'));
            }

            $perPage = $request->input('per_page', 10);
            $enrollments = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json($enrollments);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al obtener inscripciones', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'workshop_edition_id' => 'required|exists:workshop_editions,id',
            'student_type' => 'required|in:existing,external',
            'student_id' => 'required_if:student_type,existing|nullable|exists:students,id',
            'external_name' => 'nullable|string|max:255',
            'external_surname' => 'nullable|string|max:255',
            'external_ci' => 'nullable|string|max:20',
            'external_email' => 'nullable|email|max:255',
            'external_phone' => 'nullable|string|max:20',
        ]);

        try {
            DB::beginTransaction();

            $studentId = null;

            if ($data['student_type'] === 'existing') {
                if (empty($data['student_id'])) {
                    DB::rollBack();
                    return response()->json(['message' => 'Selecciona un estudiante.'], 422);
                }
                $studentId = $data['student_id'];
            } else {
                $user = User::create([
                    'name' => $data['external_name'],
                    'first_lastname' => $data['external_surname'],
                    'email' => $data['external_email'] ?? ($data['external_ci'] . '@externo.com'),
                    'ci' => $data['external_ci'],
                    'cellphone' => $data['external_phone'] ?? '',
                    'password' => Hash::make($data['external_ci']),
                    'status' => 1,
                ]);

                $student = Student::create([
                    'user_id' => $user->id,
                ]);

                UserRoles::create([
                    'user_id' => $user->id,
                    'role_id' => 4,
                ]);

                $studentId = $student->id;
            }

            $edition = WorkshopEdition::findOrFail($data['workshop_edition_id']);
            $currentCount = $edition->enrollments()->where('status', 'Activo')->count();

            if ($currentCount >= $edition->capacity) {
                DB::rollBack();
                return response()->json(['message' => 'La edición ha alcanzado su capacidad máxima.'], 422);
            }

            $exists = WorkshopEnrollment::where('student_id', $studentId)
                ->where('workshop_edition_id', $data['workshop_edition_id'])
                ->exists();

            if ($exists) {
                DB::rollBack();
                return response()->json(['message' => 'El estudiante ya está inscrito en esta edición.'], 422);
            }

            $code = 'T-' . str_pad($studentId, 4, '0', STR_PAD_LEFT) . '-' . str_pad($edition->id, 4, '0', STR_PAD_LEFT);

            $enrollment = WorkshopEnrollment::create([
                'student_id' => $studentId,
                'workshop_edition_id' => $data['workshop_edition_id'],
                'enrolled' => now(),
                'code' => $code,
                'status' => 'Activo',
            ]);

            $enrollment->load('student.user', 'edition.workshop');

            DB::commit();

            return response()->json($enrollment, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al crear inscripción', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, WorkshopEnrollment $enrollment)
    {
        $data = $request->validate([
            'status' => 'required|in:Activo,Completado,Retirado',
        ]);

        try {
            $enrollment->update($data);
            return response()->json($enrollment);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al actualizar inscripción', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(WorkshopEnrollment $enrollment)
    {
        try {
            $enrollment->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['message' => 'No se pudo eliminar inscripción.', 'error' => $e->getMessage()], 500);
        }
    }
}
